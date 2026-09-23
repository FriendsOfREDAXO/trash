<?php

/**
 * Trash AddOn - Einstellungen
 *
 * @package redaxo\trash
 */

use FriendsOfREDAXO\trash\QuickUndo;

$addon = rex_addon::get('trash');
$csrf = rex_csrf_token::factory('trash_settings');

if ('save' === rex_post('func', 'string')) {
    if (!$csrf->isValid()) {
        echo rex_view::error(rex_i18n::msg('trash_undo_csrf'));
    } else {
        $addon->setConfig('quick_undo', (bool) rex_post('quick_undo', 'int', 0));
        $addon->setConfig('quick_undo_timeout', max(5, min(300, rex_post('quick_undo_timeout', 'int', QuickUndo::DEFAULT_TIMEOUT))));
        echo rex_view::success(rex_i18n::msg('trash_settings_saved'));
    }
}

$quickUndo = (bool) $addon->getConfig('quick_undo', true);
$timeout = (int) $addon->getConfig('quick_undo_timeout', QuickUndo::DEFAULT_TIMEOUT);

$content = '<div class="checkbox"><label><input type="checkbox" name="quick_undo" value="1"' . ($quickUndo ? ' checked' : '') . '> '
    . rex_i18n::msg('trash_settings_quick_undo') . '</label></div>'
    . '<p class="help-block">' . rex_i18n::msg('trash_settings_quick_undo_notice') . '</p>';

$content .= '<div class="form-group">'
    . '<label class="control-label" for="trash-timeout">' . rex_i18n::msg('trash_settings_timeout') . '</label>'
    . '<input class="form-control" type="number" id="trash-timeout" name="quick_undo_timeout" min="5" max="300" value="' . $timeout . '">'
    . '<p class="help-block">' . rex_i18n::msg('trash_settings_timeout_notice') . '</p>'
    . '</div>';

$fragment = new rex_fragment();
$fragment->setVar('title', rex_i18n::msg('trash_settings_title'), false);
$fragment->setVar('body', $content, false);
$body = $fragment->parse('core/page/section.php');

$fragment = new rex_fragment();
$fragment->setVar('elements', [
    ['field' => '<button class="btn btn-save" type="submit" name="func" value="save">' . rex_i18n::msg('trash_settings_save') . '</button>'],
], false);
$buttons = $fragment->parse('core/form/submit.php');

echo '<form action="' . rex_url::currentBackendPage() . '" method="post">'
    . $csrf->getHiddenField()
    . $body
    . $buttons
    . '</form>';
