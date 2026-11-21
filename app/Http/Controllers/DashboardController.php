<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\StudySession;
use App\Services\StudyStats;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $stats = StudyStats::forUser($user);

        $rooms = $user->rooms()->with('owner:id,name')->withCount('members')->orderByDesc('room_members.created_at')->get();

        $recentSessions = StudySession::where('user_id', $user->id)
            ->with('room:id,name,code')
            ->latest('started_at')
            ->limit(5)
            ->get();

        // Tugas dari room yang saya ikuti (sebagai anggota, bukan owner) yang belum saya kumpulkan.
        $pendingAssignments = Assignment::whereIn('room_id', $rooms->where('owner_id', '!=', $user->id)->pluck('id'))
            ->whereDoesntHave('submissions', fn ($q) => $q->where('user_id', $user->id))
            ->with('room:id,name,code')
            ->orderByRaw('due_at IS NULL, due_at ASC')
            ->limit(5)
            ->get();

        $openSession = StudySession::where('user_id', $user->id)->open()->with('room:id,name,code')->latest()->first();
        $openSession?->sync();
        if ($openSession && ! $openSession->isOpen()) {
            $openSession = null;
        }

        return view('dashboard', compact('stats', 'rooms', 'recentSessions', 'pendingAssignments', 'openSession'));
    }

    public function updateGoal(Request $request)
    {
        $data = $request->validate([
            'daily_goal_minutes' => ['required', 'integer', 'min:10', 'max:720'],
        ], [
            'daily_goal_minutes.min' => 'Target harian minimal 10 menit.',
            'daily_goal_minutes.max' => 'Target harian maksimal 720 menit (12 jam).',
        ]);

        $request->user()->forceFill(['daily_goal_minutes' => $data['daily_goal_minutes']])->save();

        return back()->with('status', 'Target harian diperbarui menjadi '.$data['daily_goal_minutes'].' menit.');
    }
}
