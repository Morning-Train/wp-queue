<?php namespace Morningtrain\WP\Queue\Commands;

use Morningtrain\WP\Queue\Classes\Worker;

class QueueCommand {

	protected static $command = 'queue';

    public static function register() : void
    {
        // Do not load if CLI is not running
        if(!defined('WP_CLI') || !WP_CLI || !class_exists('WP_CLI')) {
            return;
        }

        \WP_CLI::add_command(static::$command, static::class);
    }

	/**
	 * Starts a queue worker
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Class name of the queue (standard Job)
	 *
	 * ## EXAMPLES
	 *      wp queue start Job
	 *
	 * @after_wp_load
	 */
	public function start($args, $assoc_args) {
		\WP_CLI::log("Starting queue worker: {$args[0]}");

        $worker = Worker::getInstance($args[0]);

		if(empty($worker)) {
			\WP_CLI::error('Queue does not exist');
		}

        $worker->start();

		\WP_CLI::log("Queue worker stopped");
	}

	/**
	 * Stops a queue worker
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Class name of the queue (standard Job), or use all to stop all running queue workers
	 *
	 * ## EXAMPLES
	 *      wp queue stop Job
	 *      wp queue stop all
	 *
	 * @after_wp_load
	 */
	public function stop($args, $assoc_args) {
		if($args[0] === 'all') {
			\WP_CLI::log("Stopping all queue workers");

            $queues = Worker::getWorkers();

			if(empty($queues)) {
				\WP_CLI::error('No queues exists');
			}
		} else {
			\WP_CLI::log("Stopping queue worker: {$args[0]}");

            $queues = array(Worker::getInstance($args[0]));

			if(empty($queues)) {
				\WP_CLI::error('Queue does not exist');
			}
		}

		foreach($queues as $queue) {
			$queue->stop();

			\WP_CLI::log("Queue worker stopped: {$queue->getWorkerSlug()}");
		}
	}

	/**
	 * Run single job
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Class name of the queue
	 *
	 * <id>
	 * : ID (Primary Key) on the job you will run
	 *
	 * [--untouched]
	 * : Whether or not to set run date on the job
	 *
	 * [--force]
	 * : Whether to force the job to run or not even though it has been running before
	 *
	 * ## EXAMPLES
	 *      wp queue run Job 101 --untouched --force
	 */
	public function run($args, $assoc_args) {
		\WP_CLI::log("Running job {$args[1]} in queue worker {$args[0]}");

        $worker = Worker::getInstance($args[0]);

		if(empty($worker)) {
			return \WP_CLI::error('Queue does not exist');
		}

		$job = $worker->getJob($args[1]);

		if($job === null) {
			\WP_CLI::error('Job does not exist');
		}

		if($job->run_date !== null && !isset($assoc_args['force'])) {
			\WP_CLI::error("Job has already been running with result: {$job->result}");
		}

        if(!isset($assoc_args['untouched'])) {
            $worker->updateRunDate($job->id);
        }

		return $worker->handleJob($job, isset($assoc_args['untouched']));
	}

	/**
	 * List jobs
	 *
	 * ## OPTIONS
	 *
	 * ## EXAMPLES
	 *      wp queue list
	 */
	public function list($args, $assoc_args) {
        $_queues = Worker::getWorkers();

		if(empty($_queues)) {
			\WP_CLI::error('No queues exists');
			return;
		}

        $queues = array();

		foreach($_queues as $_queue) {

			$queues[] = array(
				'Name' => $_queue->getWorkerSlug(),
				'Table' => $_queue->getTableName(),
				'Active workers' => count($_queue->getActiveWorkers()),
				'Last stopped' => $_queue->getStopTime()
			);

		}

		\WP_CLI\Utils\format_items('table', $queues, array('Name', 'Table', 'Active workers', 'Last stopped'));
	}
}