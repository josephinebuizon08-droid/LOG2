<?php

function badge(string $text, string $colorClasses): string {
    $safe = htmlspecialchars($text, ENT_QUOTES);
    return "<span class=\"px-2 py-0.5 rounded-full {$colorClasses} text-xs\">{$safe}</span>";
}

/** Monospace ID/plate-style tag, e.g. manifest_tag('TRP-001') */
function manifest_tag(string $text): string {
    $safe = htmlspecialchars($text, ENT_QUOTES);
    return "<span class=\"manifest px-2 py-0.5 text-xs text-slate-700 dark:text-slate-300\">{$safe}</span>";
}

/** Maps a status string to a badge color pair. Extend as new statuses appear. */
function status_color(?string $status): string {
    $map = [
        'active'          => 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400',
        'available'       => 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400',
        'completed'       => 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400',
        'approved'        => 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400',
        'resolved'        => 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400',
        'verified'        => 'bg-green-50 dark:bg-green-500/10 text-green-600 dark:text-green-400',

        'scheduled'       => 'bg-blue-50 dark:bg-blue-500/10 text-blue-600 dark:text-blue-400',
        'pending'         => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'in progress'     => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'in transit'      => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'reserved'        => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'reported'        => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'investigating'   => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',
        'flagged'         => 'bg-amber-50 dark:bg-amber-500/10 text-amber-600 dark:text-amber-400',

        'maintenance'     => 'bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400',
        'delayed'         => 'bg-orange-50 dark:bg-orange-500/10 text-orange-600 dark:text-orange-400',

        'cancelled'       => 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400',
        'rejected'        => 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400',
        'out of service'  => 'bg-red-50 dark:bg-red-500/10 text-red-600 dark:text-red-400',
        'inactive'        => 'bg-slate-100 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400',
        'archived'        => 'bg-slate-100 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400',
    ];
    $key = strtolower(trim((string) $status));
    return $map[$key] ?? 'bg-slate-100 dark:bg-slate-700/50 text-slate-500 dark:text-slate-400';
}

/** Formats a driver/user row's display name from first/last name columns. */
function full_name(?string $first, ?string $last): string {
    $name = trim(($first ?? '') . ' ' . ($last ?? ''));
    return $name !== '' ? $name : '—';
}

/** Formats a numeric code as e.g. MNT-001 for display-only IDs. */
function code_id(string $prefix, int $id): string {
    return $prefix . '-' . str_pad((string) $id, 3, '0', STR_PAD_LEFT);
}

/**
 * Renders a pill/chip-style sub-tab nav (dark bar, active tab shown as a
 * solid light "chip"). $tabs = ['key' => 'Label', ...]
 */
function render_subtabs(string $moduleKey, array $tabs, string $activeKey): void {
    $links = [];
    foreach ($tabs as $key => $label) {
        $isActive = $key === $activeKey;

        $classes = $isActive
            ? 'bg-blue-600 text-white shadow-sm'
            : 'text-slate-500 dark:text-slate-400 hover:text-slate-700 dark:hover:text-slate-200';

        $links[] = "<button type=\"button\" class=\"subtab-link px-4 py-1.5 rounded-lg text-sm font-medium transition-colors {$classes}\" data-module=\"{$moduleKey}\" data-subtab=\"{$key}\">{$label}</button>";
    }

    echo '<div class="inline-flex items-center gap-1 p-1 rounded-xl mb-4 border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900">'
        . implode('', $links)
        . '</div>';
}