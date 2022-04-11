<?php namespace Morningtrain\WP\Queue;

use Morningtrain\WP\Core\Abstracts\AbstractModule;
use Morningtrain\WP\Core\Classes\ClassLoader;
use Morningtrain\WP\Queue\Classes\Worker;

class Module extends AbstractModule {

    protected bool $use_views = false;

    /**
     * @param string|array $job_queues
     */
    public function __construct($job_queues = array('job_queue')) {
        foreach((array) $job_queues as $job_queue) {
            static::registerJobQueue($job_queue);
        }
    }

    public function init() {
        parent::init();

        $this->registerNamedDir('wp-cli_commands', 'Commands');

        \Morningtrain\WP\CLICommand\Module::registerFolder($this->getNamedDirPath('wp-cli_commands'));
    }

    /**
     * Regiser job queue
     * @param string $job_queue
     * @return void
     */
    public static function registerJobQueue($job_queue = 'job_queue') {
        Worker::register($job_queue);
    }
}