<?php

/*  ATICA - Web application for supporting Quality Management Systems
  Copyright (C) 2009-2015: Luis-Ramón López López

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

$app->map('/modificar/:folderid/:id(/:return(/:data1(/:data2(/:data3(/:data4)))))', function ($folderId, $id, $return = null, $data1 = null, $data2 = null, $data3 = null, $data4 = null)
        use ($app, $user, $config, $organization, $preferences) {
    if (!$user) {
        $app->redirect($app->urlFor('login'));
    }
    $delivery = getDeliveryById($id);
    if (false == $delivery) {
        $app->redirect($app->urlFor('tree'));
    }
    $revisions = parseArray(getRevisionsObjectByDelivery($id));
    $uploaders = getDeliveryUploadersById($id);

    $data = array();
    $category = array();
    $parent = array();

    $folder = getFolder($organization['id'], $folderId);
    $uploadProfiles = parseArray(getPermissionProfiles($folderId, 1));
    $managerProfiles = parseArray(getPermissionProfiles($folderId, 0));
    $userProfiles = parseArray(getUserProfiles($user['id'], $organization['id'], true));
    $profile = getProfile($delivery['profile_id']);

    if (isset($_SESSION['slim.flash']['last_url'])) {
        $app->flash('last_url', $_SESSION['slim.flash']['last_url']);
    }

    if ($delivery['item_id']) {
        $deliveredItem = getItemById($organization['id'], $delivery['item_id']);
        $deliveredItem['display_name'] = parseVariables($deliveredItem['display_name'], $organization, $user, $profile);
    }
    else {
        $deliveredItem = array();
    }

    $items = parseArray(getFolderProfileDeliveryItems($delivery['profile_id'], $folderId));

    $isManager = $user['is_admin'];
    foreach ($managerProfiles as $upload) {
        if (isset($userProfiles[$upload['id']])) {
            $isManager = true;
            break;
        }
    }
    // si no tiene permisos para editar la entrega, salir
    // tiene permiso si:
    // - Es administrador o gestor de la carpeta ($isManager)
    // - La revisión activa es suya
    if ((!$isManager) && ($revisions[$delivery['current_revision_id']]['uploader_person_id'] != $user['id'])) {
        $app->redirect($app->urlFor('login'));
    }

    $uploadAs = array();

    foreach ($uploadProfiles as $item) {
        if (null == $item['display_name']) {
            $data = parseArray(getSubprofiles($item['id']));
            if (count($data)>1) {
                foreach($data as $subItem) {
                    if (null != $subItem['display_name']) {
                        $uploadAs[$subItem['id']] = $subItem;
                    }
                }
            }
            else {
                $uploadAs[$item['id']] = $item;
            }
        }
        else {
            $uploadAs[$item['id']] = $item;
        }
    }

    getTree($organization['id'], $app, $folder['category_id'], $category, $parent);

    if (isset($_POST['save'])) {
        $delivery->set('display_name', $_POST['displayname']);
        $delivery->set('description', strlen($_POST['description']) > 0 ? $_POST['description'] : null);
        if ($isManager) {
            if (isset($_POST['creation_year'])) {
                $delivery->set('creation_date', $_POST['creation_year'] . '-'. $_POST['creation_month'] . '-' . $_POST['creation_day'] . ' ' .$_POST['creation_hour'] . ':' . $_POST['creation_minute'] . ':00');
            }
        }
        if (isset($_POST['item']) && (($_POST['item'] == 0) || isset($items[$_POST['item']]))) {
            $delivery->set('item_id', ($_POST['item'] == 0) ? null : $_POST['item']);
        }
        if (isset($_POST['profile']) && isset($uploadAs[$_POST['profile']])) {
            $delivery->set('profile_id', ($_POST['profile'] == 0) ? null : $_POST['profile']);
        }
        $delivery->save();
        $app->flash('save_ok', 'ok');
        $app->redirect($app->request()->getPathInfo());
    }

    if (isset($_POST['default'])) {
        $delivery->set('current_revision_id', $_POST['default']);
        $delivery->save();
        $app->flash('save_ok', 'ok');
        $app->redirect($app->request()->getPathInfo());
    }

    if (isset($_POST['remove'])) {
        ORM::get_db()->beginTransaction();

        $revision = getRevisionById($organization['id'], $_POST['remove']);

        $ok = ($revision !== false);

        $ok = $ok && deleteDocumentById($revision['original_document_id'], $preferences);
        $ok = $ok && $revision->delete();

        if ($ok) {
            $app->flash('save_ok', 'delete');
            ORM::get_db()->commit();
        }
        else {
            $app->flash('save_error', 'delete');
            ORM::get_db()->rollback();
        }
        $app->redirect($app->request()->getPathInfo());
    }

    if (isset($_POST['delete'])) {
        ORM::get_db()->beginTransaction();
        $ok = true;
        foreach($revisions as $revision) {
            if ($ok) {
                $status = deleteDocumentById($revision['original_document_id'], $preferences);
                $ok = $ok && $status;
            }
        }

        foreach($revisions as $revision) {
            $ok = $ok && $revision->delete();
        }

        if ($delivery['profile_id']) {
            checkItemUpdateStatus($folderId, $delivery['profile_id']);
        }

        $ok = $ok && $delivery->delete();

        if ($ok) {
            $app->flash('save_ok', 'delete');
            ORM::get_db()->commit();
        }
        else {
            $app->flash('save_error', 'delete');
            ORM::get_db()->rollback();
        }

        $app->redirect($app->urlFor('tree', array('id' => $category['id'])));
    }

    if (isset($_POST['new']) && isset($_FILES['document']) && isset($_FILES['document']['name'][0]) && is_uploaded_file($_FILES['document']['tmp_name'][0])) {

        $newRevision = getMaxRevisionNrByDelivery($id) + 1;

        // añadir nueva revisión en una transacción
        ORM::get_db()->beginTransaction();

        $hash = sha1_file($_FILES['document']['tmp_name'][0]);
        $filesize = filesize($_FILES['document']['tmp_name'][0]);
        $documentDestination = createDocumentFolder($preferences['upload.folder'], $hash);

        if (null !== $delivery['item_id']) {
            $ext = pathinfo($_FILES['document']['name'][0], PATHINFO_EXTENSION);
            $filename = parseVariables($deliveredItem['document_name'], $organization, $user, $profile) . '.' . $ext;
        }
        else {
            $filename = $_FILES['document']['name'][0];
        }

        $documentData = getDocumentDataByHash($hash);
        $newData = (false == $documentData);

        $revision = createRevision($id, $user['id'], $filename, $documentDestination, $hash, $filesize, $newRevision, $_POST['description_new']);

        $ok = ($revision !== false);

        if ($ok && $newData) {
            $ok = move_uploaded_file($_FILES['document']['tmp_name'][0], $preferences['upload.folder'] . $documentDestination);
        }

        if ($ok) {
            $delivery->set('current_revision_id', $revision['id']);
            $delivery->save();
            $app->flash('save_ok', 'ok');
            ORM::get_db()->commit();
        }
        else {
            if ($newData) {
                unlink($documentDestination);
            }
            $app->flash('save_error', 'error');
            ORM::get_db()->rollback();
        }

        $app->redirect($app->request()->getPathInfo());
    }

    $breadcrumb = array(
        array('display_name' => 'Árbol', 'target' => $app->urlFor('tree')),
        array('display_name' => $parent['display_name'], 'target' => $app->urlFor('tree')),
        array('display_name' => $category['display_name'], 'target' => $app->urlFor('tree', array('id' => $category['id']))),
        array('display_name' => 'Modificar entrega')
    );

    switch ($return) {
        case 0:
            $lastUrl = $app->urlFor('tree', array('id' => $data1));
            break;

        case 1:
            $lastUrl = $app->urlFor('event', array('pid' => $data1, 'aid' => $data2, 'id' => $data3));
            break;

        case 2:
            $lastUrl = $app->urlFor('upload', array('id' => $folderId, 'return' => $data1, 'data1' => $data2, 'data2' => $data3, 'data3' => $data4));
            break;

        default:
            $lastUrl = $app->urlFor('frontpage');
    }

    $app->render('manage_delivery.html.twig', array(
        'navigation' => $breadcrumb, 'search' => false,
        'select2' => true,
        'url' => $app->request()->getPathInfo(),
        'category' => $category,
        'folder' => $folder,
        'item' => $deliveredItem,
        'items' => $items,
        'delivery' => $delivery,
        'revisions' => $revisions,
        'uploaders' => $uploaders,
        'is_manager' => $isManager,
        'base' => $config['calendar.base_week'],
        'upload_profiles' => $uploadProfiles,
        'manager_profiles' => $managerProfiles,
        'user_profiles' => $userProfiles,
        'upload_as' => $uploadAs,
        'last_url' => $lastUrl,
        'data' => $data));

})->name('modify')->via('GET', 'POST');

$app->map('/revision/:folderid/:id', function ($folderId, $id) use ($app, $user, $config, $organization, $preferences) {
    if (!$user['is_admin']) {
        $app->redirect($app->urlFor('login'));
    }

    $revision = getRevisionById($organization['id'], $id);

    if (false == $revision) {
        $app->redirect($app->urlFor('tree'));
    }
    $document = getDocumentById($revision['original_document_id']);
    $delivery = getDeliveryById($revision['delivery_id']);
    $revision_nrs = getRevisionNrArrayByDelivery($revision['delivery_id'], 100, $revision['revision_nr']);
    $persons = getActivePersonsByOrganization($organization['id']);

    $data = array();
    $category = array();
    $parent = array();

    $folder = getFolder($organization['id'], $folderId);

    getTree($organization['id'], $app, $folder['category_id'], $category, $parent);

    if (isset($_SESSION['slim.flash']['last_url'])) {
        $app->flash('last_url', $_SESSION['slim.flash']['last_url']);
    }

    if (isset($_POST['save'])) {
        $document->set('download_filename', $_POST['downloadname']);
        $document->save();
        $revision->set('revision_nr', $_POST['revisionnr']);
        $revision->set('uploader_person_id', $_POST['uploader']);
        $revision->set('upload_date', $_POST['upload_year'] . '-'. $_POST['upload_month'] . '-' . $_POST['upload_day'] . ' ' .$_POST['upload_hour'] . ':' . $_POST['upload_minute'] . ':00');
        $revision->save();
        $app->flash('save_ok', 'ok');

        $app->redirect($app->request()->getPathInfo());
    }

    if (isset($_POST['delete'])) {
        ORM::get_db()->beginTransaction();
        $ok = deleteDocumentById($revision['original_document_id'], $preferences);
        $ok = $ok && $revision->delete();

        if ($ok) {
            $app->flash('save_ok', 'delete');
            ORM::get_db()->commit();
        }
        else {
            $app->flash('save_error', 'delete');
            ORM::get_db()->rollback();
        }

        $app->redirect($app->urlFor('modify', array('id' => $delivery['id'], 'folderid' => $folder['id'])));
    }

    if (isset($_POST['replace']) && isset($_FILES['document']) && isset($_FILES['document']['name'][0]) && is_uploaded_file($_FILES['document']['tmp_name'][0])) {

        // reemplazar revisión en una transacción
        ORM::get_db()->beginTransaction();

        $hash = sha1_file($_FILES['document']['tmp_name'][0]);
        $filesize = filesize($_FILES['document']['tmp_name'][0]);
        $documentDestination = createDocumentFolder($preferences['upload.folder'], $hash);
        $filename = $_FILES['document']['name'][0];

        $documentData = getDocumentDataByHash($hash);
        $newData = (false == $documentData);

        if ($newData) {
            $document = createDocument($revision['id'], $filename, $hash, $documentDestination, $filesize);
        }
        else {
            $document = getDocumentByHash($hash);
        }

        $ok = ($document !== false);

        if ($ok && $newData) {
            $ok = move_uploaded_file($_FILES['document']['tmp_name'][0], $preferences['upload.folder'] . $documentDestination);
        }

        if ($ok) {
            $revision->set('original_document_id', $document['id']);
            $revision->save();
            $app->flash('save_ok', 'ok');
            ORM::get_db()->commit();
        }
        else {
            if ($newData) {
                unlink($documentDestination);
            }
            $app->flash('save_error', 'error');
            ORM::get_db()->rollback();
        }

        $app->redirect($app->urlFor('tree', array('id' => $category['id'])));
    }

    $breadcrumb = array(
        array('display_name' => 'Árbol', 'target' => $app->urlFor('tree')),
        array('display_name' => $parent['display_name'], 'target' => $app->urlFor('tree')),
        array('display_name' => $category['display_name'], 'target' => $app->urlFor('tree', array('id' => $category['id']))),
        array('display_name' => 'Modificar entrega')
    );

    $app->render('manage_revision.html.twig', array(
        'navigation' => $breadcrumb, 'search' => false,
        'select2' => true,
        'url' => $app->request()->getPathInfo(),
        'category' => $category,
        'folder' => $folder,
        'revision' => $revision,
        'document' => $document,
        'delivery' => $delivery,
        'persons' => $persons,
        'revisions' => $revision_nrs,
        'data' => $data));

})->name('revision')->via('GET', 'POST');

$app->map('/historial/archivar/masivo', function () use ($app, $user, $config, $organization, $preferences) {

    if ((!$user) || (!$user['is_admin'])) {
        $app->redirect($app->urlFor('login'));
    }

    if ((isset($_POST['archive']) && isset($_POST['displayname']) && strlen($_POST['displayname'])) ||
        (isset($_POST['archive_old']) && isset($_POST['snapshot']))) {

        // realizar los cambios en una transacción
        ORM::get_db()->beginTransaction();

        if (isset($_POST['archive'])) {
            // crear snapshot
            $snapshot = ORM::for_table('snapshot')->create();
            $snapshot->set('organization_id', $organization['id']);
            $snapshot->set('display_name', $_POST['displayname']);
            $snapshot->set('order_nr', getLastSnapshotOrder($organization['id']) + 1000);
            $ok = $snapshot->save();
        }
        else {
            // recuperar snapshot
            $snapshot = ORM::for_table('snapshot')->
                where('organization_id',  $organization['id'])->
                where('id', $_POST['snapshot'])->
                find_one();

            if (!$snapshot) {
                $app->redirect($app->urlFor('login'));
            }

            $ok = true;
        }

        // archivar carpetas
        $ok = $ok && archiveFolders($organization['id'], $snapshot['id'], $_POST['item']);

        // borrar eventos completados
        $ok = $ok && deleteAllCompletedEvents($organization['id']);

        if ($ok) {
            $app->flash('save_ok', 'ok');
            ORM::get_db()->commit();

            $app->redirect($app->urlFor('tree'));
        }
        else {
            $app->flash('save_error', 'ok');
            ORM::get_db()->rollback();
        }
    }

    $items = getAutoCleaningFolders($organization['id']);
    $snapshots = getSnapshots($organization['id']);

    // generar barra de navegación
    $breadcrumb = array(
        array('display_name' => 'Historial', 'target' => $app->urlFor('managesnapshots')),
        array('display_name' => 'Archivado masivo de carpetas en el historial')
    );

        // lanzar plantilla
    $app->render('create_snapshot.html.twig', array(
        'select2' => true,
        'navigation' => $breadcrumb,
        'snapshots' => $snapshots,
        'items' => $items,
        'url' => $app->request()->getPathInfo()
    ));

})->name('addsnapshot')->via('GET', 'POST');

$app->map('/historial/archivar/:id(/:return(/:data1(/:data2(/:data3(/:data4)))))', function ($id, $return = null, $data1 = null, $data2 = null, $data3 = null, $data4 = null) use ($app, $user, $config, $organization, $preferences) {

    if ((!$user) || (!$user['is_admin'])) {
        $app->redirect($app->urlFor('login'));
    }

    switch ($return) {
        case 0:
            $lastUrl = $app->urlFor('tree', array('id' => $data1));
            break;

        case 1:
            $lastUrl = $app->urlFor('event', array('pid' => $data1, 'aid' => $data2, 'id' => $data3));
            break;

        case 2:
            $lastUrl = $app->urlFor('upload', array('id' => $id, 'return' => $data1, 'data1' => $data2, 'data2' => $data3, 'data3' => $data4));
            break;

        default:
            $lastUrl = $app->urlFor('frontpage');
    }

    if ((isset($_POST['archive']) && isset($_POST['displayname']) && strlen($_POST['displayname'])) ||
        (isset($_POST['archive_old']) && isset($_POST['snapshot']))) {

        // realizar los cambios en una transacción
        ORM::get_db()->beginTransaction();

        if (isset($_POST['archive'])) {
            // crear snapshot
            $snapshot = ORM::for_table('snapshot')->create();
            $snapshot->set('organization_id', $organization['id']);
            $snapshot->set('display_name', $_POST['displayname']);
            $snapshot->set('order_nr', getLastSnapshotOrder($organization['id']) + 1000);
            $ok = $snapshot->save();
        }
        else {
            // recuperar snapshot
            $snapshot = ORM::for_table('snapshot')->
            where('organization_id',  $organization['id'])->
            where('id', $_POST['snapshot'])->
            find_one();

            if (!$snapshot) {
                $app->redirect($app->urlFor('login'));
            }

            $ok = true;
        }

        // archivar carpetas
        $ok = $ok && archiveDeliveriesFromFolder($organization['id'], $snapshot['id'], $id, $_POST['item']);

        // borrar eventos completados
        $ok = $ok && deleteCompletedEventsForFolder($organization['id'], $id);

        if ($ok) {
            $app->flash('save_ok', 'ok');
            ORM::get_db()->commit();

            $app->redirect($lastUrl);
        }
        else {
            $app->flash('save_error', 'ok');
            ORM::get_db()->rollback();
        }
    }

    $items = getDeliveriesFromFolderNotInSnapshot($organization['id'], $id);
    $snapshots = getSnapshots($organization['id']);
    $folder = getFolderById($organization['id'], $id);

    // generar barra de navegación
    $breadcrumb = array(
        array('display_name' => 'Historial', 'target' => $app->urlFor('managesnapshots')),
        array('display_name' => 'Archivado de una carpeta en historial'),
        array('display_name' => $folder['display_name'])
    );

    // lanzar plantilla
    $app->render('create_folder_snapshot.html.twig', array(
        'select2' => true,
        'navigation' => $breadcrumb,
        'snapshots' => $snapshots,
        'items' => $items,
        'folder' => $folder,
        'last_url' => $lastUrl,
        'url' => $app->request()->getPathInfo()
    ));

})->name('addfoldersnapshot')->via('GET', 'POST');

$app->map('/historial/listar', function () use ($app, $user, $config, $organization, $preferences) {
    if ((!$user) || (!$user['is_admin'])) {
        $app->redirect($app->urlFor('login'));
    }

    if (isset($_POST['up']) || isset($_POST['down'])) {
        if (isset($_POST['up'])) {
            $snap1 = getSnapshotById($organization['id'], $_POST['up']);
            $snap2 = getNextSnapshot($organization['id'], $_POST['up']);
        }
        else {
            $snap1 = getSnapshotById($organization['id'], $_POST['down']);
            $snap2 = getPreviousSnapshot($organization['id'], $_POST['down']);
        }
        if (!$snap1 || !$snap2) {
            $app->redirect($app->urlFor('login'));
        }
        $order_nr = $snap1['order_nr'];
        $snap1->set('order_nr', $snap2['order_nr'])->save();
        $snap2->set('order_nr', $order_nr)->save();
    }

    if (isset($_POST['delete'])) {

        // realizar los cambios en una transacción
        ORM::get_db()->beginTransaction();

        $ok = deleteSnapshots($organization['id'], $_POST['snapshot']);

        if ($ok) {
            $app->flash('save_ok', 'delete');
            ORM::get_db()->commit();
        }
        else {
            $app->flash('save_error', 'delete');
            ORM::get_db()->rollback();
        }
    }

    $snapshots = getSnapshots($organization['id']);

    // generar barra de navegación
    $breadcrumb = array(
        array('display_name' => 'Historial', 'target' => $app->urlFor('managesnapshots')),
        array('display_name' => 'Listado de archivos', 'target' => $app->urlFor('managesnapshots'))
    );

    // lanzar plantilla
    $app->render('manage_snapshot_list.html.twig', array(
        'select2' => true,
        'navigation' => $breadcrumb,
        'snapshots' => $snapshots,
        'url' => $app->request()->getPathInfo()
    ));
})->name('managesnapshots')->via('GET', 'POST');

$app->map('/historial/archivo/:id', function ($id) use ($app, $user, $config, $organization, $preferences) {
    if ((!$user) || (!$user['is_admin'])) {
        $app->redirect($app->urlFor('login'));
    }

    $snapshot = getSnapshotById($organization['id'], $id);

    if (isset($_POST['save'])) {

        $snapshot->set('display_name', $_POST['displayname']);

        $ok = $snapshot->save();

        if ($ok) {
            $app->flash('save_ok', 'ok');
            $app->redirect($app->urlFor('managesnapshots'));
        }
        else {
            $app->flash('save_error', 'ok');
        }
    }

    // generar barra de navegación
    $breadcrumb = array(
        array('display_name' => 'Historial', 'target' => $app->urlFor('managesnapshots')),
        array('display_name' => 'Listado de archivos', 'target' => $app->urlFor('managesnapshots')),
        array('display_name' => $snapshot['display_name'])
    );

    // lanzar plantilla
    $app->render('manage_snapshot.html.twig', array(
        'select2' => true,
        'navigation' => $breadcrumb,
        'snapshot' => $snapshot,
        'url' => $app->request()->getPathInfo()
    ));
})->name('managesnapshot')->via('GET', 'POST');

function getDeliveryUploadersById($deliveryId) {
    return parseArray(ORM::for_table('person')->
        select('person.*')->
        distinct()->
        inner_join('revision', array('revision.uploader_person_id', '=', 'person.id'))->
        inner_join('delivery', array('delivery.id', '=', 'revision.delivery_id'))->
        where('delivery.id', $deliveryId)->
        find_array());
}

function getDeliveryById($deliveryId) {
    $data = ORM::for_table('delivery')->
            find_one($deliveryId);

    return $data;
}

function getRevisionById($orgId, $revisionId) {
    $data = ORM::for_table('revision')->
            select('revision.*')->
            inner_join('delivery', array('delivery.id', '=', 'revision.delivery_id'))->
            inner_join('folder_delivery', array('delivery.id', '=', 'folder_delivery.delivery_id'))->
            inner_join('folder', array('folder.id', '=', 'folder_delivery.folder_id'))->
            inner_join('category', array('category.id', '=', 'folder.category_id'))->
            where('category.organization_id', $orgId)->
            find_one($revisionId);

    return $data;
}

function getDocumentById($documentId) {
    $data = ORM::for_table('document')->
            find_one($documentId);

    return $data;
}

function getActivePersonsByOrganization($organizationId) {
    $data = ORM::for_table('person')->
            select('person.id')->
            select('person.user_name')->
            select('person.display_name')->
            inner_join('person_organization', array('person.id', '=', 'person_organization.person_id'))->
            where('person_organization.organization_id', $organizationId)->
            where('person_organization.is_active', 1)->
            order_by_asc('person.display_name')->
            find_many();

    return $data;
}

function getRevisionsObjectByDelivery($deliveryId) {
    return ORM::for_table('revision')->
            select('revision.*')->
            select('document.download_filename')->
            where('revision.delivery_id', $deliveryId)->
            inner_join('document', array('document.id', '=', 'revision.original_document_id'))->
            order_by_desc('upload_date')->
            find_many();
}

function deleteDocumentById($docId, $preferences) {
    // comprobar si existen otros documentos con la misma información
    $document = ORM::for_table('document')->find_one($docId);
    if (!$document) {
        return false;
    }

    if (ORM::for_table('document')->where('document_data_id', $document['document_data_id'])->count() == 1) {
        // solamente hay un documento con esta información... hay que borrarlo
        $document_data = ORM::for_table('document_data')->find_one($document['document_data_id']);

        // borrar físicamente del sistema de archivos si existe
        if (strlen($document_data['download_path'])>0) {
            unlink($preferences['upload.folder'] . $document_data['download_path']);
        }
        $ok = $document->delete();
        $ok = $ok && $document_data->delete();
        return $ok;
    }
    else {
        $ok = $document->delete();
        return $ok;
    }
}

function getMaxRevisionNrByDelivery($delId) {
    return ORM::for_table('revision')->
            where('delivery_id', $delId)->
            max('revision_nr');
}

function getRevisionNrArrayByDelivery($delId, $limit, $currentNr) {
    $data = range(0, getMaxRevisionNrByDelivery($delId)+$limit);
    $existing = ORM::for_table('revision')->
            select('revision.revision_nr')->
            where('delivery_id', $delId)->
            where_not_equal('revision_nr', $currentNr)->find_array();
    $nrs = array();
    foreach($existing as $nr) {
        $nrs[] = $nr['revision_nr'];
    }
    return array_diff($data, $nrs);
}

function getLastSnapshotOrder($orgId) {
    return ORM::for_table('snapshot')->
        where('organization_id', $orgId)->
        max('order_nr');
}

function getAutoCleaningFolders($orgId) {
    $data = ORM::for_table('folder_delivery')->
        select('folder.*')->
        select('category.display_name', 'category_display_name')->
        select_expr('COUNT(*)', 'total')->
        inner_join('folder', array('folder_delivery.folder_id', '=', 'folder.id'))->
        inner_join('category', array('folder.category_id', '=', 'category.id'))->
        where('category.organization_id', $orgId)->
        where('folder.auto_clean', 1)->
        where_null('folder_delivery.snapshot_id')->
        group_by('folder_delivery.folder_id')->
        order_by_asc('folder.category_id')->
        find_array();

    return $data;
}

function archiveFolders($orgId, $snapId, $folders) {

    $ok = ORM::for_table('folder')->
        select('folder.*')->
        inner_join('category', array('category.id', '=', 'category_id'))->
        where_in('folder.id', $folders)->
        where('category.organization_id', $orgId)->
        find_result_set()->
        set('has_snapshot', 1)->
        save();

    $ok = $ok && ORM::for_table('delivery')->
        inner_join('folder_delivery', array('delivery.id', '=', 'delivery_id'))->
        where_in('folder_id', $folders)->
        where_null('snapshot_id')->
        find_result_set()->
        set('item_id', null)->
        save();

    $ok = $ok && ORM::for_table('folder_delivery')->
        use_id_column(array('folder_id', 'delivery_id'))->
        where_in('folder_id', $folders)->
        where_null('snapshot_id')->
        find_result_set()->
        set('snapshot_id', $snapId)->
        save();

    return $ok;
}

function archiveDeliveriesFromFolder($orgId, $snapId, $folderId, $deliveries) {

    $ok = ORM::for_table('folder')->
        select('folder.*')->
        inner_join('category', array('category.id', '=', 'category_id'))->
        where('folder.id', $folderId)->
        where('category.organization_id', $orgId)->
        find_result_set()->
        set('has_snapshot', 1)->
        save();

    $ok = $ok && ORM::for_table('delivery')->
        inner_join('folder_delivery', array('delivery.id', '=', 'delivery_id'))->
        where('folder_delivery.folder_id', $folderId)->
        where_null('snapshot_id')->
        where_in('delivery.id', $deliveries)->
        find_result_set()->
        set('item_id', null)->
        save();

    $ok = $ok && ORM::for_table('folder_delivery')->
        use_id_column(array('folder_id', 'delivery_id'))->
        where('folder_id', $folderId)->
        where_in('delivery_id', $deliveries)->
        where_null('snapshot_id')->
        find_result_set()->
        set('snapshot_id', $snapId)->
        save();

    return $ok;
}

function deleteEventsIn($events) {
    $ok = true;
    if (count($events) > 0) {
        $ok = ORM::for_table('completed_event')->
        where_in('event_id', $events)->
        delete_many();
    }

    return $ok;
}

function deleteCompletedEventsForFolder($orgId, $folderId) {
    $events = ORM::for_table('event')->
        select('event.id')->
        where('event.organization_id', $orgId)->
        where('event.folder_id', $folderId)->
        where('event.is_automatic', 1)->
        find_array();

    $events = array_column($events, 'id');

    $ok = deleteEventsIn($events);

    return $ok;
}

function deleteAllCompletedEvents($orgId) {
    $events = ORM::for_table('event')->
        select('event.id')->
        where('event.organization_id', $orgId)->
        find_array();

    $events = array_column($events, 'id');

    $ok = deleteEventsIn($events);

    return $ok;
}

function getSnapshots($orgId) {
    return ORM::for_table('folder_delivery')->
        select('snapshot.*')->
        select_expr('COUNT(*)', 'total')->
        inner_join('snapshot', array('snapshot.id', '=', 'snapshot_id'))->
        where('snapshot.organization_id', $orgId)->
        having_not_null('snapshot_id')->
        order_by_desc('order_nr')->
        group_by('snapshot_id')->
        find_array();
}

function getDeliveriesFromFolderNotInSnapshot($orgId, $folderId) {
    return ORM::for_table('delivery')->
        select('delivery.*')->
        inner_join('folder_delivery', array('delivery.id', '=', 'delivery_id'))->
        inner_join('folder', array('folder.id', '=', 'folder_delivery.folder_id'))->
        inner_join('category', array('folder.category_id', '=', 'category.id'))->
        where('category.organization_id', $orgId)->
        where('folder_delivery.folder_id', $folderId)->
        where_null('snapshot_id')->
        find_array();
}

function getSnapshotById($orgId, $snapId) {
    return ORM::for_table('snapshot')->
        where('organization_id', $orgId)->
        where('id', $snapId)->
        find_one();
}

function getNextSnapshot($orgId, $snapId) {
    $snap = getSnapshotById($orgId, $snapId);

    return ORM::for_table('snapshot')->
        where('organization_id', $orgId)->
        where_gt('order_nr', $snap['order_nr'])->
        order_by_asc('order_nr')->
        find_one();
}

function getPreviousSnapshot($orgId, $snapId) {
    $snap = getSnapshotById($orgId, $snapId);

    return ORM::for_table('snapshot')->
        where('organization_id', $orgId)->
        where_lt('order_nr', $snap['order_nr'])->
        order_by_desc('order_nr')->
        find_one();
}

function deleteSnapshots($orgId, $snapshots) {
    return ORM::for_table('snapshot')->
        where('organization_id', $orgId)->
        where_id_in($snapshots)->
        delete_many();
}
