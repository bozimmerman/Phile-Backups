<?php
/*
 Copyright 2025-2025 Bo Zimmerman

 Licensed under the Apache License, Version 2.0 (the "License");
 you may not use this file except in compliance with the License.
 You may obtain a copy of the License at

 http://www.apache.org/licenses/LICENSE-2.0

 Unless required by applicable law or agreed to in writing, software
 distributed under the License is distributed on an "AS IS" BASIS,
 WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 See the License for the specific language governing permissions and
 limitations under the License.
*/

return [
    'version'        => '1.0.0',
    'app_name'       => 'Phile-Backups',
    'admin_password' => 'password',

    'db' => [
        'type'    => 'sqlite',
        'path'    => __DIR__ . '/data/phile-backups.db',
        'host'    => 'localhost',
        'name'    => 'phile_backups',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8',
        'port'    => 3306,
    ],

    'data_dir' => __DIR__ . '/data',
];
