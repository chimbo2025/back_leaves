<?php

namespace App\Listeners;

use App\Events\LateReturnDetected;
use App\Events\LeaveBalanceReminderDue;
use App\Events\LeaveEndingSoon;
use App\Events\LeavePlanConfirmed;
use App\Events\LeavePlanExpired;
use App\Events\LeavePlanReadyForSelection;
use App\Events\LeaveRequestApproved;
use App\Events\LeaveRequestRejected;
use App\Events\LeaveRequestReturned;
use App\Events\LeaveRequestSubmitted;
use App\Events\LeaveStartingSoon;
use App\Events\LeaveWaiverDecided;
use App\Events\ReturnReminderDue;
use App\Services\LeaveNotificationService;
use Illuminate\Events\Dispatcher;

/**
 * Phase 4 — routes every leave domain event to LeaveNotificationService.
 *
 * One subscriber rather than thirteen listener classes: the mapping is a plain
 * table, and keeping it in a single file makes it obvious which events produce
 * a notification and which do not. Registered in AppServiceProvider via
 * Event::subscribe, so `php artisan event:list` also sees it.
 */
class LeaveNotificationSubscriber
{
    public function __construct(protected LeaveNotificationService $notifications) {}

    public function onSubmitted(LeaveRequestSubmitted $event): void
    {
        $this->notifications->requestSubmitted($event->request, $event->approver);
    }

    public function onApproved(LeaveRequestApproved $event): void
    {
        $this->notifications->requestApproved($event->request);
    }

    public function onRejected(LeaveRequestRejected $event): void
    {
        $this->notifications->requestRejected($event->request, $event->actor, $event->comments);
    }

    public function onReturned(LeaveRequestReturned $event): void
    {
        $this->notifications->requestReturned($event->request, $event->comments);
    }

    public function onLeaveStartingSoon(LeaveStartingSoon $event): void
    {
        $this->notifications->leaveStartingSoon($event->request);
    }

    public function onLeaveEndingSoon(LeaveEndingSoon $event): void
    {
        $this->notifications->leaveEndingSoon($event->request);
    }

    public function onReturnReminder(ReturnReminderDue $event): void
    {
        $this->notifications->returnReminder($event->return);
    }

    public function onLateReturn(LateReturnDetected $event): void
    {
        $this->notifications->lateReturnDetected($event->return);
    }

    public function onBalance(LeaveBalanceReminderDue $event): void
    {
        $this->notifications->balanceReminder($event->employee, $event->leaveTypeId, $event->balance);
    }

    public function onWaiverDecided(LeaveWaiverDecided $event): void
    {
        $this->notifications->waiverDecided($event->waiver, $event->approved);
    }

    public function onPlanReady(LeavePlanReadyForSelection $event): void
    {
        $this->notifications->planReadyForSelection($event->plan);
    }

    public function onPlanConfirmed(LeavePlanConfirmed $event): void
    {
        $this->notifications->planConfirmed($event->plan);
    }

    public function onPlanExpired(LeavePlanExpired $event): void
    {
        $this->notifications->planExpired($event->plan);
    }

    public function subscribe(Dispatcher $events): void
    {
        $events->listen(LeaveRequestSubmitted::class, [$this, 'onSubmitted']);
        $events->listen(LeaveRequestApproved::class, [$this, 'onApproved']);
        $events->listen(LeaveRequestRejected::class, [$this, 'onRejected']);
        $events->listen(LeaveRequestReturned::class, [$this, 'onReturned']);
        $events->listen(LeaveStartingSoon::class, [$this, 'onLeaveStartingSoon']);
        $events->listen(LeaveEndingSoon::class, [$this, 'onLeaveEndingSoon']);
        $events->listen(ReturnReminderDue::class, [$this, 'onReturnReminder']);
        $events->listen(LateReturnDetected::class, [$this, 'onLateReturn']);
        $events->listen(LeaveBalanceReminderDue::class, [$this, 'onBalance']);
        $events->listen(LeaveWaiverDecided::class, [$this, 'onWaiverDecided']);
        $events->listen(LeavePlanReadyForSelection::class, [$this, 'onPlanReady']);
        $events->listen(LeavePlanConfirmed::class, [$this, 'onPlanConfirmed']);
        $events->listen(LeavePlanExpired::class, [$this, 'onPlanExpired']);
    }
}
