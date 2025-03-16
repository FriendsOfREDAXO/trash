<?php
$package = rex_addon::get('trash');
echo rex_view::title(rex_i18n::msg('trash'));
rex_be_controller::includeCurrentPageSubPath();
