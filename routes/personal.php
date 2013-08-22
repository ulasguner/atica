<?php

/*  ATICA - Web application for supporting Quality Management Systems
  Copyright (C) 2009-2013: Luis-Ramón López López

  This program is free software: you can redistribute it and/or modify
  it under the terms of the GNU Affero General Public License as published by
  the Free Software Foundation, either version 3 of the License, or
  (at your option) any later version.

  This program is distributed in the hope that it will be useful,
  but WITHOUT ANY WARRANTY; without even the implied warranty of
  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
  GNU Affero General Public License for more details.

  You should have received a copy of the GNU Affero General Public License
  along with this program.  If not, see [http://www.gnu.org/licenses/]. */

$app->get('/bienvenida', function () use ($app, $user) {
    if (!$user) {
        $app->redirect($app->urlFor('login'));
    }
    $breadcrumb = array(array('display_name' => 'Primer acceso', 'target' => '#'));
    $app->render('welcome.html.twig', array('navigation' => $breadcrumb));
})->name('welcome');

$app->get('/personal(/:id)', function ($id = NULL) use ($app, $user) {
    if (!$user) {
        $app->redirect($app->urlFor('login'));
    }
    $breadcrumb = array(array('display_name' => 'Datos personales', 'target' => '#'));
    $app->render('personal.html.twig', array('navigation' => $breadcrumb));
})->name('personal');