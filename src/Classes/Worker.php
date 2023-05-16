<?php namespace Morningtrain\WP\Queue\Classes;

use DateTime;

class Worker {

    protected static array $instances = [];

    protected string $workerSlug;

    protected ?string $uniqueIdentifier = null;
    protected DateTime|null $startedTime = null;

    /**
     * Sleep time if no job is found (in seconds)
     * @var int
     */
    protected static $sleepTime = 10;

    public static function getInstance(string $workerSlug): ?static
    {
        $calledClass = get_called_class();

        if(!isset(self::$instances[$calledClass][$workerSlug]))
        {
            return null;
        }

        return static::$instances[$calledClass][$workerSlug];
    }

    public static function createInstance(string $workerSlug): static
    {
        $instance = new static($workerSlug);

        $className = (new \ReflectionClass($instance))->getName();

        static::$instances[$className][$workerSlug] = $instance;

        return static::$instances[$className][$workerSlug];
    }

    public static function getOrCreateInstance(string $workerSlug): static
    {
        $instance = static::getInstance($workerSlug);

        if(empty($instance)) {
            $instance = self::createInstance($workerSlug);
        }

        return $instance;
    }

    public static function getWorker(string $workerSlug): static
    {
        return static::getOrCreateInstance($workerSlug);
    }

    public static function register(string $workerSlug): static
    {
        $worker = static::getOrCreateInstance($workerSlug);

        $worker->maybeCreateDBTable();

        return $worker;
    }

    protected function __construct(string $workerSlug)
    {
        $this->workerSlug = $workerSlug;
    }

    /**
     * Maybe create DB table
     * @return void
     */
    protected function maybeCreateDBTable(): void
    {
        global $wpdb;

        $version = '2.0.0';

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
    public function getTableName(bool $prefixed = true): string
    {
        if(!empty($this->getWorkerSlug())) {
            $tableName = '';

            if(isset($prefixed)) {
                global $wpdb;

                $tableName .= $wpdb->prefix;
            }

            $tableName .= $this->getWorkerSlug();

            return $tableName;
        }

        return '';
    }

    /**
     * Get Context slug
     * @return string
     */
    public function getWorkerSlug(): string
    {
        return $this->workerSlug;
    }

    /**
     * Start and run QueueWorker
     */
    public function start(): void
    {
        set_time_limit(0);

        while ($this->shouldRun()) {
            if (!$this->handleNextJob()) {
                sleep(static::getSleepTime());
            }
        }

        $this->clearQueueInfo();
    }

    protected static function getSleepTime(): int
    {
        return static::$sleepTime;
    }

    /**
     * Get started time for current run
     * @return DateTime|string
     */
    protected function getStartedTime(?string $format = null): DateTime|string
    {
        if(empty($this->startedTime)) {
            $this->startedTime = new DateTime(current_time('mysql'));
        }

        if(!empty($format)) {
            if($format === 'mysql') {
                return $this->startedTime->format('Y-m-d H:i:s');
            }

            return $this->startedTime->format($format);
        }

        return $this->startedTime;
    }

    /**
     * Unique identifier to identify running tasks
     * @return null
     */
    protected function getUniqueIdentifier(): string
    {
        if(empty($this->uniqueIdentifier)) {
            $this->uniqueIdentifier = md5($this->getWorkerSlug() . $this->getStartedTime('mysql') . uniqid(true));
        }

        return $this->uniqueIdentifier;
    }

    /**
     * Update running and start time
     * @return void
     */
    protected function updateRunningTime(): void
    {
        set_transient(
            $this->getTransientName(),
            array(
                'start_time' => $this->getStartedTime(),
                'last_heart_beat' => current_time('mysql'),
                'unique_identifier' => $this->getUniqueIdentifier()
            ),
            max(static::getSleepTime() * 2, 60)
        );
    }

    /**
     * Get name of transient
     * @return string
     */
    protected function getTransientName(): string
    {
        return 'job_queue-' . $this->getWorkerSlug() . '-' . $this->getUniqueIdentifier();
    }

    /**
     * Fetch next job and handle it
     * @return bool
     */
    protected function handleNextJob(): bool
    {
        global $wpdb;
        $tableName = $this->getTableName();
        $now = current_time('mysql');

        // Avoid mysql errors with DB gone
        if(!@$wpdb->check_connection()) {
            return false;
        }

        $wpdb->query("LOCK TABLES $tableName WRITE");

        $job = $wpdb->get_row("SELECT * FROM {$tableName} WHERE run_date IS NULL AND date <= '{$now}' ORDER BY priority, date, id ASC");

        if(!empty($job)) {
            $this->updateRunDate($job->id);
        }

        $wpdb->query("UNLOCK TABLES");

        if(empty($job)) {
            return false;
        }

        $this->handleJob($job);

        return true;
    }

    public function getJob(int $id): ?object
    {
        global $wpdb;
        $tableName = $this->getTableName();

        return $wpdb->get_row("SELECT * FROM {$tableName} WHERE id = {$id}");
    }

    /**
     * Flush caches:
     * WP cache, ACF cache
     */
    protected static function flushCache(): void
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

        gc_collect_cycles();
    }

    /**
     * Shall the queue still run?
     * @return bool
     */
    protected function shouldRun() : bool
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
    protected function shouldStop(): bool
    {
        $stopTime = $this->getStopTime();

        return !empty($stopTime) && $stopTime >= $this->getStartedTime('mysql');
    }

    /**
     * Get stop option name
     * @return string
     */
    public function getStopOptionName(): string
    {
        return 'job_queue-' . $this->getWorkerSlug() . '-stop';
    }

    /**
     * Get stop time option for queue type
     * @return mixed
     */
    public function getStopTime(): mixed
    {
        return get_option($this->getStopOptionName());
    }

    /**
     * Add a stop marker to the database so running jobs will stop
     * @return void
     */
    public function stop(): void
    {
        update_option($this->getStopOptionName(), current_time('mysql'), false);
    }

    /**
     * return info about workers running (OBS: will not work if Memcache, Redis or similar is activated)
     * @return array
     */
    public function getActiveWorkers(): array
    {
        global $wpdb;

        $transientName = '_transient_job_queue_' . $this->getWorkerSlug() . '-%';

        $sql = "SELECT option_name 
				FROM {$wpdb->options} 
				WHERE option_name LIKE '{$transientName}'";

        $results = $wpdb->get_results($sql);

        $workers = array();

        foreach ($results as $result) {
            $worker = get_transient($result->option_name);

            if (!empty($worker['last_heartbeat']) && new \DateTime($worker['last_heartbeat']) < (new \DateTime(current_time('mysql')))->modify('-' . max(static::getSleepTime() * 2, 60) . ' seconds')) {
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
    public function clearQueueInfo(): void
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
    public function createJob(Callable $callback, mixed $arg = null, ?DateTime $date = null, int $priority = 10): int|bool
    {
        global $wpdb;

        $jobProps = static::prepareJob($callback, $arg, $date, $priority);

        return $wpdb->insert($this->getTableName(), $jobProps);
   }

    /**
     * Prepare args for job queue table
     * @param $callback
     * @param $args
     * @param $date
     * @param $priority
     * @return array
     */
    protected static function prepareJob(Callable $callback, mixed $args = null, ?DateTime $date = null, int$priority = 10): array
    {
        $component = null;

        $date = empty($date) ? current_time('mysql') : $date->format('Y-m-d H:i:s');

        if (is_array($callback)) {
            $component = $callback[0];
            $callback = $callback[1];
        }

        return array(
            'date' => $date,
            'callback' => $callback,
            'component' => $component,
            'args' => is_array($args) || is_object($args) ? json_encode($args) : $args,
            'priority' => $priority,
            'created_date' => current_time('mysql')
        );
    }

    /**
     * Handle job
     */
    public function handleJob($job, $untouched = false) : mixed
    {
        $args = $job->args;

        if($args !== null) {
            $decoded_args = json_decode($args, true);

            if($decoded_args !== null) {
                $args = $decoded_args;
            }
        }

        $result = $this->doJob($job->component, $job->callback, $args);

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
    public function doJob($component = null, $callback = null, $args = array()) : mixed
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
    public function updateRunDate($id) : int|false
    {
        global $wpdb;

        return $wpdb->update($this->getTableName(), array('run_date' => current_time('mysql'), 'updated_date' => current_time('mysql')), array('id' => $id));
    }

    /**
     * Update result
     * @param $result
     */
    public function updateResult($id, $result) : int|false
    {
        global $wpdb;

        if(is_array($result) || is_object($result)) {
            $result = json_encode($result);
        }

        return $wpdb->update($this->getTableName(), array('result' => $result, 'updated_date' => current_time('mysql')), array('id' => $id));
    }

    /**
     * Get instances
     * @return static[]
     */
    public static function getWorkers() : array
    {
        $calledClass = get_called_class();

        if(empty(static::$instances[$calledClass])) {
            return array();
        }

        return static::$instances[$calledClass];
    }
}