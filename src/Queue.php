<?php namespace Morningtrain\WP\Queue;

use Morningtrain\WP\Queue\Commands\QueueCommand;
use Morningtrain\WP\Queue\Classes\Worker;

class Queue {

    static bool $initialized = false;

    public static function registerWorker(string $workerSlug = 'job_queue') : Worker
    {
        static::init();

        return Worker::register($workerSlug);
    }

    public static function registerWorkers(array $workerSlugs) : void
    {
        foreach($workerSlugs as $workerSlug) {
            static::registerWorker($workerSlug);
        }
    }

    protected static function init() : void
    {
        if(static::$initialized) {
            return;
        }

        QueueCommand::register();

        static::$initialized = true;
    }

}