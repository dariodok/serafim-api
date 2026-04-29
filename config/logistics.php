<?php

return [
    'package_max_weight_grams' => (int) env('LOGISTICS_PACKAGE_MAX_WEIGHT_GRAMS', 20000),
    'package_max_volume_cm3' => (int) env('LOGISTICS_PACKAGE_MAX_VOLUME_CM3', 250000),
];
