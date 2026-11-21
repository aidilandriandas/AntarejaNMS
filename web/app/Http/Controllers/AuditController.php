<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;

class AuditController extends Controller
{
    public function index()
    {
        return view('audit.index', ['logs' => AuditLog::with('user')->orderByDesc('at')->paginate(50)]);
    }
}
