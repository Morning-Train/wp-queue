<?php namespace Morningtrain\WP\Queue\Classes;

use Morningtrain\WP\Core\Abstracts\AbstractSingletonContext;

class Worker extends AbstractSingletonContext {

    protected $unique_identifier = null;
    protected $started_time = null;

    /**
     * Sleep time if no job is found (in seconds)
     * @var int
     */
    protected static $sleep_time = 10;

    protected function __construct($context_slug)
    {
        parent::__construct($context_slug);
    }

    public static function register($context_slug) {
        $worker = static::getInstance($context_slug);

        $worker->maybeCreateDBTable();
    }

    public static function getWorker($context_slug): ? self
    {
        $called_class = get_called_class();

        if(!isset(self::$instances[$called_class][$context_slug]))
        {
            return null;
        }

        return self::$instances[$called_class][$context_slug];
    }


    /**
     * Maybe create DB table
     * @return void
     */
    protected function maybeCreateDBTable()
    {
        global $wpdb;

        $version = '1.0.0';

        if ($version == \get_option($this->getTableName() . '_db_version')) {
            return;
        }

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');


        $sql = "CREATE TABLE {$this->getTableName()}
            (
                id int unsigned NOT NULL AUTO_INCREMENT,
                date datetime NOT NULL, 
                callback varchar(256) NOT NULL,
                component varchar(256) NOT NULL,                
                args text,
                priority int DEFAULT 10 NOT NULL,
                run_date datetime,
                result text,
                created_date datetime NOT NULL,
                updated_date datetime,
                PRIMARY KEY (id),
				UNIQUE KEY id (id)
            ) {$wpdb->get_charset_collate()}
        ";

        \dbDelta($sql);

        \update_option($this->getTableName() . '_db_version', $version);
    }

    /**
     * Get table name
     * @return string
     */
    public function getTableName($prefixed = true) {
        if(!empty($this->getContextSlug())) {
            $_table_name = '';

            if(isset($prefixed)) {
                global $wpdb;

                $_table_name .= $wpdb->prefix;
            }

            $_table_name .= $this->getContextSlug();

            return $_table_name;
        }

        return null;
    }

    /**
     * Get Context slug
     * @return string
     */
    public function getContextSlug() {
        return $this->context_slug;
    }

    /**
     * Start and run QueueWorker
     */
    public function start()
    {
        set_time_limit(0);

        while ($this->shouldRun()) {
            if (!$this->handleNextJob()) {
                sleep(static::$sleep_time);
            }
        }

        $this->clearQueueInfo();
    }

    /**
     * Get started time for current run
     * @return null
     */
    protected function getStartedTime()
    {
        if (empty($this->started_time)) {
            $this->started_time = current_time('mysql');
        }

        return $this->started_time;
    }

    /**
     * Unique identifier to identify running tasks
     * @return null
     */
    protected function getUniqueIdentifier()
    {
        if (empty($this->unique_identifier)) {
            $this->unique_identifier = md5($this->getContextSlug() . $this->getStartedTime() . uniqid(true));
        }

        return $this->unique_identifier;
    }

    /**
     * Update running and start time
     * @return void
     */
    protected function updateRunningTime()
    {
        set_transient(
            $this->getTransientName(),
            array(
                'start_time' => $this->getStartedTime(),
                'last_heartbeat' => current_time('mysql'),
                'unique_identifier' => $this->getUniqueIdentifier()
            ),
            max(static::$sleep_time * 2, 60)
        );
    }

    /**
     * Get name of transient
     * @return string
     */
    protected function getTransientName() {
        return 'job_queue-' . $this->getContextSlug() . '-' . $this->getUniqueIdentifier();
    }

    /**
     * Fetch next job and handle it
     * @return bool
     */
    protected function handleNextJob()
    {
        global $wpdb;
        $table_name = $this->getTableName();
        $now = current_time('mysql');

        // Avoid mysql errors with DB gone
        if (!@$wpdb->check_connection()) {
            return false;
        }

        $wpdb->query("LOCK TABLES $table_name WRITE");
        $job = $wpdb->get_row("SELECT * FROM {$table_name} WHERE run_date IS NULL AND date <= '{$now}' ORDER BY priority, date, id ASC");

        if (!empty($job)) {
            $this->updateRunDate($job->id);
        }

        $wpdb->query("UNLOCK TABLES");

        if (empty($job)) {
            return false;
        }

        $this->handleJob($job);

        return true;
    }

    public function getJob($id) {
        global $wpdb;
        $table_name = $this->getTableName();

        return $wpdb->get_row("SELECT * FROM {$table_name} WHERE id = {$id} ORDER BY priority, date, id ASC");
    }

    /**
     * Flush caches:
     * WP cache, ACF cache
     */
    protected static function flushCache()
    {
        wp_cache_flush();

        // ACF STORES
        if (function_exists('acf_get_store')) {
            $store_names = array(
                'fields',
                'values',
            );

            foreach ($store_names as $store_name) {
                try {
                    acf_get_store($store_name)->reset();
                } catch (\Exception $e) {
                    // DO NOTHING
                }
            }

        }
    }

    /**
     * Shall the queue still run?
     * @return bool
     */
    protected function shouldRun()
    {
        // Flush cache to avoid cached options and meta data
        static::flushCache();

        $this->updateRunningTime();

        return !$this->shouldStop();
    }

    /**
     * Is a stop time set in option and is it greater than start time?
     * @return bool
     */
    protected function shouldStop()
    {
        $stop_time = $this->getStopTime();

        return !empty($stop_time) && $stop_time >= $this->getStartedTime();
    }

    /**
     * Get stop option name
     * @return string
     */
    public function getStopOptionName() {
        return 'job_queue-' . $this->getContextSlug() . '-stop';
    }

    /**
     * Get stop time option for queue type
     * @return false|mixed|void
     */
    public function getStopTime()
    {
        return get_option($this->getStopOptionName());
    }

    /**
     * Add a stop marker to the database so running jobs will stop
     * @return void
     */
    public function stop()
    {
        update_option($this->getStopOptionName(), current_time('mysql'), false);
    }

    /**
     * return info about workers running (OBS: will not work if Memcache, Redis or similar is activated)
     * @return array
     */
    public function getActiveWorkers()
    {
        global $wpdb;

        $transient_name = '_transient_job_queue_' . $this->getContextSlug() . '-%';

        $sql = "SELECT option_name 
				FROM {$wpdb->options} 
				WHERE option_name LIKE '{$transient_name}'";

        $results = $wpdb->get_results($sql);

        $workers = array();

        foreach ($results as $result) {
            $worker = get_transient($result->option_name);

            if (!empty($worker['last_heartbeat']) && new \DateTime($worker['last_heartbeat']) < (new \DateTime(current_time('mysql')))->modify('-' . max(static::$sleep_time * 2, 60) . ' seconds')) {
                delete_transient($result->option_name);
                continue;
            }

            $workers[] = $worker;
        }

        return $workers;
    }

    /**
     * Delete info about this worker
     * @return void
     */
    public function clearQueueInfo()
    {
        delete_transient($this->getTransientName());
    }

    /**
     * Create a new scheduled job
     *
     * @param mixed $callback The callback to run when the job is to be executed.
     *
     * @param string $arg The data to be passed to the callback
     *
     * @param string|\DateTime $date mySQL formatted date for when to run the job
     *
     * @param int $priority Jobs will be ordered by this ASC
     *
     * @return bool             true if job was successfully created
     */
    public function createJob($callback, $arg = NULL, $date = NULL, $priority = 10)
    {
        global $wpdb;

        if (is_a($date, 'DateTime')) {
            $date = $date->format('Y-m-d H:i:s');
        }

        $job_props = static::prepareJob($callback, $arg, $date, $priority);

        return $wpdb->insert($this->getTableName(), $job_props);
   }

    /**
     * Prepare args for job queue table
     * @param $callback
     * @param $args
     * @param $date
     * @param $priority
     * @return array
     */
    protected static function prepareJob($callback, $args = array(), $date = NULL, $priority = 10)
    {
        $component = NULL;
        $date = (empty($date)) ? \current_time('mysql') : $date;

        if (is_array($callback)) {
            $component = $callback[0];
            $callback = $callback[1];
        }

        $job_props = array(
            'date' => $date,
            'callback' => $callback,
            'component' => $component,
            'args' => json_encode($args),
            'priority' => $priority,
            'created_date' => current_time('mysql')
        );

        return $job_props;
    }

    /**
     * Handle job
     */
    public function handleJob($job, $untouched = false)
    {
        $result = $this->doJob($job->component, $job->callback, json_decode($job->args, true));

        if(is_wp_error($result)) {
            $result = $result->get_error_message();
        }

        if(!$untouched) {
            $this->updateResult($job->id, $result);
        }

        return $result;
    }

    /**
     * Do the job
     * @return false|mixed|string
     */
    public function doJob($component = null, $callback = null, $args = array())
    {
        try {
            if (!empty($component)) {
                if (class_exists($component)) {
                    if (method_exists($component, $callback)) {
                        return call_user_func_array(array($component, $callback), (array) $args);
                    } else {
                        return new \WP_Error('invalid_callback', sprintf('Method "%s" not found for component "%s"', $callback, $component));
                    }
                } else {
                    return new \WP_Error('invalid_component', sprintf('Component "%s" not found', $component));
                }
            } else {
                if (function_exists($callback)) {
                    return call_user_func_array($callback, $args);
                } else {
                    return new \WP_Error('invalid_callback', sprintf('Function "%s" not found', $callback));
                }
            }
        } catch (\Exception $e) {
            return new \WP_Error('job_failed', $e->getMessage());
        }
    }

    /**
     * Update run date
     */
    public function updateRunDate($id)
    {
        global $wpdb;

        return $wpdb->update($this->getTableName(), array('run_date' => current_time('mysql')), array('id' => $id));
    }

    /**
     * Update result
     * @param $result
     */
    public function updateResult($id, $result)
    {
        global $wpdb;

        return $wpdb->update($this->getTableName(), array('result' => $result), array('id' => $id));
    }

    /**
     * Get instances
     * @return static[]
     */
    public static function getWorkers() {
        $called_class = get_called_class();

        if(empty(static::$instances[$called_class])) {
            return array();
        }

        return static::$instances[$called_class];
    }
}