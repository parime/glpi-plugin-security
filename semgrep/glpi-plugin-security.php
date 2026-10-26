<?php

// Cas de test des règles de glpi-plugin-security.yml (semgrep --test semgrep/).
// « ruleid: » : la ligne suivante doit être signalée ; « ok: » : elle ne doit pas l'être.

function generatedReports(string $file, int $entity): void
{
    // ruleid: glpi-generated-document-recursive
    (new Document())->add(['name' => 'Rapport', 'entities_id' => $entity, 'is_recursive' => 1, '_filename' => [$file]]);
    // ruleid: glpi-generated-document-recursive
    (new Document())->add(['_filename' => [$file], 'entities_id' => $entity, 'is_recursive' => 1]);
    // ok: glpi-generated-document-recursive
    (new Document())->add(['name' => 'Rapport', 'entities_id' => $entity, 'is_recursive' => 0, '_filename' => [$file]]);
    // ok: glpi-generated-document-recursive
    (new Entity())->update(['id' => $entity, 'is_recursive' => 1]);
}

class PluginDemoNotificationTargetThing extends NotificationTarget
{
    // ruleid: glpi-notification-target-core-id
    public const TARGET_RESPONSIBLE = 1;
    // ok: glpi-notification-target-core-id
    public const TARGET_OWNER = 1140;
    // ok: glpi-notification-target-core-id
    public const MAX_ROWS = 50;
}

class PluginDemoThing extends CommonDBTM
{
    // ok: glpi-notification-target-core-id
    public const TARGET_DAYS = 30;
}

function install($DB, string $query): void
{
    // ruleid: glpi-or-die
    $DB->doQuery($query) or die($DB->error());
    // ok: glpi-or-die
    $DB->doQuery($query);
    // ok: glpi-or-die
    // Plus de « or die($DB->error()) » : doQuery() lève une exception.
    // ruleid: glpi-or-die
    $DB->doQuery($query) || die('Erreur');
}

function messages(array $input): void
{
    // ruleid: glpi-session-message-unescaped-input
    Session::addMessageAfterRedirect(sprintf(__('"%s" is not valid.'), $input['cve_id']), false, ERROR);
    // ruleid: glpi-session-message-unescaped-input
    Session::addMessageAfterRedirect('Unknown: ' . $_POST['name'], false, ERROR);
    // ok: glpi-session-message-unescaped-input
    Session::addMessageAfterRedirect(sprintf(__('"%s" is not valid.'), htmlescape($input['cve_id'])), false, ERROR);
}

function visibility(CommonDBTM $item, int $id): bool
{
    // ruleid: glpi-visibility-from-canviewitem-only
    if ($item->getFromDB($id) && $item->canViewItem()) {
        return true;
    }
    // ok: glpi-visibility-from-canviewitem-only
    return $item->can($id, READ);
}
