<?php

// Баланс новых разломов. Числа ID — штатные ID юнитов ядра, не записи unit_id.
$categoryDefaults = [
  'fleet_share_percent' => 60,
  'resource_percent' => 75,
  'resource_spread_percent' => 20,
  // Веса распределяют бюджет стоимости, а не количество кораблей.
  'fleet_weights' => [202 => 3, 203 => 6, 204 => 18, 205 => 12, 206 => 16,
    207 => 18, 209 => 5, 211 => 6, 213 => 8, 215 => 8],
  'defence_weights' => [401 => 20, 402 => 20, 403 => 15, 404 => 15, 405 => 15, 406 => 15],
];

// Средние стоимости образуют геометрическую прогрессию между tiny и great.
// Столбцы: название, цвет, вес появления, предел по галактике, стоимость гарнизона,
// средний уровень технологий, множитель времени всех фаз (часы — ниже).
$profiles = [
  'tiny' => ['Крошечный', '#ADFF2F', 30, -1, 80000000, 8, 1],
  'small' => ['Малый', '#32CD32', 25, -1, 978676804, 12, 1],
  'medium' => ['Средний', '#FFFFFF', 20, -1, 11972603592, 18, 1],
  'large' => ['Большой', '#FFD700', 15, -1, 146466367788, 24, 1],
  'huge' => ['Огромный', '#FF4040', 7, 100, 1791790459627, 30, 1.2],
  'colossal' => ['Колоссальный', '#BF70FF', 2.5, 10, 21919797013468, 33, 1.5],
  'great' => ['Великий', '#7B2CBF', 0.5, 2, 268154961162000, 35, 2],
];
$categories = [];
foreach ($profiles as $key => $profile) {
  list($name, $color, $weight, $limit, $points, $tech, $durationScale) = $profile;
  $categories[$key] = array_merge($categoryDefaults, [
    'name' => $name,
    'color' => $color,
    'weight' => $weight,
    'limit' => $limit,
    'garrison_points' => ['mean' => $points, 'spread_percent' => 20],
    'tech' => [
      106 => ['mean' => $tech, 'spread' => 2], // Шпионаж.
      109 => ['mean' => $tech, 'spread' => 2], // Оружие.
      110 => ['mean' => $tech, 'spread' => 2], // Щиты.
      111 => ['mean' => $tech, 'spread' => 2], // Броня.
    ],
    'phases_hours' => [
      'energy' => ['mean' => 0.75 * $durationScale, 'spread_percent' => 20],
      'active' => ['mean' => 10 * $durationScale, 'spread_percent' => 20],
      'unstable' => ['mean' => 2.5 * $durationScale, 'spread_percent' => 20],
      'closed' => ['mean' => 1.5 * $durationScale, 'spread_percent' => 20],
    ],
  ]);
}

return [
  'target_active_per_system' => 2,
  'tick_seconds' => 15,
  'generation_seconds' => 300,
  'energy_seconds' => [1800, 3600],
  'active_seconds' => [28800, 43200],
  'unstable_seconds' => [7200, 10800],
  'closed_seconds' => [3600, 7200],
  'refresh_seconds' => 30,
  'fleet_limit_multiplier' => 1.25,
  'acs_enabled' => true,
  'categories' => $categories,
];
