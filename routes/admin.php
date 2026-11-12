<?php

use Illuminate\Support\Facades\Route;
use Livewire\Volt\Volt;

/*
 * Every admin route passes EnsureAdmin, which ALSO authenticates on the admin guard.
 * (A bare `auth:admin` would be sorted ahead of it by Laravel and would turn a
 * student's 403 into a login redirect.) EnsureAdmin enforces student/admin
 * separation, the current authorized-roster integrity check and the fixed role. It is also registered
 * as Livewire persistent middleware, so Livewire update requests are re-checked.
 *
 * There is intentionally NO route to create, delete, promote or demote admins.
 */
Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
    Volt::route('/', 'admin.home')->name('home');
    Volt::route('approvals', 'admin.approvals')->name('approvals');
    Volt::route('access-issues', 'admin.access-issues')->name('access-issues');
    Volt::route('data-center', 'admin.data-center')->name('data-center');
    Volt::route('students', 'admin.students')->name('students');
    Volt::route('elections', 'admin.elections')->name('elections');
    Volt::route('elections/new', 'admin.elections.new')->name('elections.new');
    Volt::route('elections/{election}', 'admin.elections.show')->whereNumber('election')->name('elections.show');
});
