<?php

namespace App\Support\System;

/**
 * How long a scheduled task that sends emails may work in one run. The cron runs `schedule:run` every minute on a shared host with no queue worker, so
 * a task that sends mail loops until its list is done or this time is up, and carries on at its next run (the lists are "not yet sent" lists, so nothing
 * is lost or sent twice). Well under a minute, so one slow mail server cannot pile runs on top of each other.
 */
final class TaskBudget
{
    public const SECONDS = 40;

    /** The moment (microtime) a run that starts now has to stop. */
    public static function until(): float
    {
        return microtime(true) + self::SECONDS;
    }
}
