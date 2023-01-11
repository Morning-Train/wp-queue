<?php namespace Morningtrain\WP\Queue\Abstracts;

use Morningtrain\WP\Queue\Classes\Worker;
use DateTime;

abstract class AbstractJob {

    protected static int $priority = 10;
    protected static string $worker = 'job_queue';

    protected static function getCallback() : Callable
    {
        return [static::class, 'handle'];
    }

    protected static function getPriority() : int
    {
        return static::$priority;
    }

    protected static function getWorkerSlug() : string
    {
        return static::$worker;
    }

    protected static function getWorker() : Worker
    {
        return Worker::getInstance(static::getWorkerSlug());
    }

    /**
     * Dispatch a job
     * @param mixed $args Arguments to send to the callback when doing the job.
     * @param string|DateTime $date Date and time the job should run, if null it will run as soon as possible.
     * @return bool
     */
    public static function dispatch(mixed $args = null, string|DateTime $date = null) : int|false
    {
        return static::getWorker()->createJob(static::getCallback(), $args, $date, static::getPriority());
    }
}