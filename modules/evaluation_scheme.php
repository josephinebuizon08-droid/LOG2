<?php
/* Driver evaluation scheme -- single source of truth.
 * Used by monitoring.php (form + table) and evaluations_actions.php (server-side
 * scoring), so a weight or level change only ever needs to be made here.
 */

// Five performance areas, each scored 1-5 by the evaluator. The overall score is
// the weighted average of the five (weights add up to 1.0). To change a weight,
// label or hint, edit it here -- the form, the live total and the table all
// follow this one list.
$evalAreas = [
    ['key' => 'safety_score',             'label' => 'Safety',             'icon' => 'ti-shield-check',    'weight' => 0.30, 'hint' => 'Follows traffic rules, safe driving, no incidents'],
    ['key' => 'reliability_score',        'label' => 'Reliability',        'icon' => 'ti-alarm',           'weight' => 0.25, 'hint' => 'On-time departure and arrival, completes assigned trips'],
    ['key' => 'vehicle_management_score', 'label' => 'Vehicle Management', 'icon' => 'ti-truck',           'weight' => 0.20, 'hint' => 'Vehicle care, pre/post-trip checks, fuel use'],
    ['key' => 'service_score',            'label' => 'Service',            'icon' => 'ti-heart-handshake', 'weight' => 0.15, 'hint' => 'Courtesy and responsiveness to passengers and requesters'],
    ['key' => 'professionalism_score',    'label' => 'Professionalism',    'icon' => 'ti-briefcase',       'weight' => 0.10, 'hint' => 'Conduct, appearance, follows instructions and reporting'],
];

// Overall score -> Performance Level (checked top to bottom, first match wins).
$evalLevels = [
    ['min' => 4.5, 'label' => 'Excellent', 'bg' => '#dcfce7', 'fg' => '#15803d'],
    ['min' => 3.5, 'label' => 'Very Good', 'bg' => '#dbeafe', 'fg' => '#1d4ed8'],
    ['min' => 2.5, 'label' => 'Good',      'bg' => '#fef9c3', 'fg' => '#a16207'],
    ['min' => 1.5, 'label' => 'Fair',      'bg' => '#ffedd5', 'fg' => '#c2410c'],
    ['min' => 0,   'label' => 'Poor',      'bg' => '#fee2e2', 'fg' => '#b91c1c'],
];

if (!function_exists('eval_level')) {
    function eval_level($score, array $levels) {
        if ($score === null || $score === '') return null;
        foreach ($levels as $lvl) {
            if ((float) $score >= $lvl['min']) return $lvl;
        }
        return end($levels);
    }
}