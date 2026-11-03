<?php

use App\Filters\ApiAuth;
use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', static fn () => service('response')->setJSON(['name' => 'Borhan ERP API', 'version' => 'v1']));

$routes->options('api/(:any)', static fn () => service('response')->setStatusCode(204));

$routes->group('api/v1', ['namespace' => 'App\Controllers\Api'], static function ($r) {
    $r->post('auth/login', 'Auth::login');
    $r->post('auth/refresh', 'Auth::refresh');

    $r->group('', ['filter' => ApiAuth::class], static function ($r) {
        $r->post('auth/logout', 'Auth::logout');
        $r->post('auth/password', 'Auth::password');
        $r->post('auth/device-token', 'Auth::deviceToken');
        $r->get('me', 'Auth::me');
        $r->put('me', 'Auth::updateMe');

        $r->get('users', 'Users::index');
        $r->post('users', 'Users::save');
        $r->put('users/(:segment)', 'Users::save/$1');
        $r->delete('users/(:segment)', 'Users::delete/$1');

        // Visa
        $r->get('visa-files', 'Visa::index');
        $r->get('visa-files/summary', 'Visa::summary');
        $r->get('visa-files/board', 'Visa::board');
        $r->post('visa-files', 'Visa::create');
        $r->get('visa-files/(:segment)', 'Visa::show/$1');
        $r->put('visa-files/(:segment)', 'Visa::update/$1');
        $r->post('visa-files/(:segment)/stage', 'Visa::stage/$1');
        $r->post('visa-files/(:segment)/payments', 'Visa::payment/$1');
        $r->post('visa-files/(:segment)/costs', 'Visa::cost/$1');
        $r->post('visa-files/(:segment)/cancel', 'Visa::cancel/$1');
        $r->put('visa-files/(:segment)/schedules', 'Visa::schedules/$1');
        $r->post('visa-files/(:segment)/documents', 'Visa::document/$1');
        $r->put('visa-files/(:segment)/documents/(:segment)', 'Visa::document/$1/$2');
        $r->delete('visa-files/(:segment)/documents/(:segment)', 'Visa::document/$1/$2');
        $r->get('visa-batches', 'Visa::batches');
        $r->post('visa-batches', 'Visa::createBatch');

        // Projects (create/update/delete through generic CRUD)
        $r->get('projects', 'Projects::index');
        $r->get('projects/(:segment)', 'Projects::show/$1');
        $r->get('projects/(:segment)/entries', 'Projects::entries/$1');
        $r->post('projects/(:segment)/entries', 'Projects::addEntry/$1');
        $r->delete('projects/(:segment)/entries/(:segment)', 'Projects::deleteEntry/$1/$2');

        // Finance
        $r->get('finance/balances', 'Finance::balances');
        $r->get('transactions', 'Finance::transactions');
        $r->post('transactions/party-payment', 'Finance::partyPayment');
        $r->post('transactions/transfer', 'Finance::transfer');
        $r->post('transactions/(:segment)/void', 'Finance::void/$1');
        $r->get('parties/(:segment)/ledger', 'Finance::partyLedger/$1');
        $r->get('accounts/(:segment)/ledger', 'Finance::accountLedger/$1');
        $r->get('expenses', 'Finance::expenses');
        $r->post('expenses', 'Finance::createExpense');
        $r->post('expenses/(:segment)/(approve|reject)', 'Finance::decideExpense/$1/$2');
        $r->delete('expenses/(:segment)', 'Finance::deleteExpense/$1');

        // HR
        $r->get('attendance', 'Hr::attendance');
        $r->post('attendance', 'Hr::markAttendance');
        $r->get('advances', 'Hr::advances');
        $r->post('advances', 'Hr::createAdvance');
        $r->get('payroll', 'Hr::runs');
        $r->post('payroll/generate', 'Hr::generate');
        $r->get('payroll/(:segment)', 'Hr::run/$1');
        $r->put('payroll-items/(:segment)', 'Hr::updateItem/$1');
        $r->post('payroll/(:segment)/approve', 'Hr::approve/$1');
        $r->post('payroll/(:segment)/pay', 'Hr::pay/$1');

        // Reports
        $r->get('dashboard', 'Reports::dashboard');
        $r->get('reports/pnl', 'Reports::pnl');
        $r->get('reports/trial-balance', 'Reports::trialBalance');
        $r->get('reports/dues', 'Reports::dues');
        $r->get('reports/visa-sales', 'Reports::visaSales');
        $r->get('reports/commissions', 'Reports::commissions');
        $r->get('reports/expenses', 'Reports::expenses');

        // Misc
        $r->get('notifications', 'Misc::notifications');
        $r->post('notifications/read', 'Misc::readNotifications');
        $r->get('search', 'Misc::search');
        $r->post('attachments', 'Misc::upload');
        $r->get('attachments/(:segment)', 'Misc::file/$1');
        $r->get('activity', 'Misc::activity');

        // Generic CRUD (keep last)
        $r->get('(:segment)', 'Crud::index/$1');
        $r->get('(:segment)/(:segment)', 'Crud::show/$1/$2');
        $r->post('(:segment)', 'Crud::create/$1');
        $r->put('(:segment)/(:segment)', 'Crud::update/$1/$2');
        $r->delete('(:segment)/(:segment)', 'Crud::delete/$1/$2');
    });
});
