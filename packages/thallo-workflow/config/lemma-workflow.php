<?php

declare(strict_types=1);

return [
    // NOTE: enable/disable is NOT configured here — the capability switchboard in the app's
    // config/lemma.php ('capabilities' => ['lemma.workflow' => false]) is the only gate.

    // When true, the submitter may approve their own submission (tiny-team escape hatch).
    // Default false: an approval means a second person looked at the draft.
    'allow_self_review' => (bool) env('WORKFLOW_ALLOW_SELF_REVIEW', false),
];
