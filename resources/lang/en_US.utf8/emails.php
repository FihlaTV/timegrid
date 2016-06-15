<?php

return [
  'user' => [
    'welcome'     => ['subject' => 'Welcome to timegrid.io'],
    'appointment' => [
      'reserved'  => ['subject' => 'Information of your reservation'],
      'confirmed' => ['subject' => 'Your appointment at :business was confirmed'],
      'canceled'  => ['subject' => 'Your appointment at :business was canceled'],
      'validate'  => ['subject' => 'Confirm your appointment at :business'],
    ],
  ],
  'manager' => [
    'appointment' => [
      'reserved' => ['subject' => 'You have a reservation'],
    ],
    'business' => [
      'report' => ['subject' => ':date :business Schedule'],
    ],
  ],
];
