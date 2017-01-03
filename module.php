<?php
// webtrees - Custom pages module
//
// webtrees: Web based Family History software
// Copyright (C) 2015 Łukasz Wileński.
// Copyright (C) 2017 Sebastian Gąsiorek
//
// This program is free software; you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation; either version 2 of the License, or
// (at your option) any later version.
//
// This program is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with this program; if not, write to the Free Software
// Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
//

namespace Morfic\WebtreesAddons\MorficPagesModule;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Controller\PageController;
use Fisharebest\Webtrees\Database;
use Fisharebest\Webtrees\Filter;
use Fisharebest\Webtrees\Functions\FunctionsEdit;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleBlockInterface;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Theme;
use Fisharebest\Webtrees\Tree;

class MorficPagesModule extends AbstractModule implements ModuleBlockInterface, ModuleConfigInterface, ModuleMenuInterface {

	public function __construct() {
		parent::__construct('morfic_pages');
	}

	// Extend class Module
	public function getTitle() {
		return I18N::translate('Morfic Pages');
	}

	public function getMenuTitle() {
		return I18N::translate('Pages');
	}

	// Extend class Module
	public function getDescription() {
		return I18N::translate('Display pages prepared by user.');
	}

	// Implement Module_Menu
	public function defaultMenuOrder() {
		return 40;
	}

	// Extend class Module
	public function defaultAccessLevel() {
		return Auth::PRIV_NONE;
	}

	// Implement Module_Config
	public function getConfigLink() {
		return 'module.php?mod=' . $this->getName() . '&amp;mod_action=admin_config';
	}

	// Implement class Module_Block
	public function getBlock($block_id, $template=true, $cfg=null) {
	}

	// Implement class Module_Block
	public function loadAjax() {
		return false;
	}

	// Implement class Module_Block
	public function isUserBlock() {
		return false;
	}

	// Implement class Module_Block
	public function isGedcomBlock() {
		return false;
	}

	// Implement class Module_Block
	public function configureBlock($block_id) {
	}

	// Implement Module_Menu
	public function getMenu() {
		global $controller, $WT_TREE;
		
		$args                = array();
		$args['module_name'] = $this->getName();
		
		$block_id = Filter::get('block_id');
		$default_block = Database::prepare(
			"SELECT block_id FROM `##block` WHERE module_name=:module_name AND block_order IN (SELECT MIN(block_order) FROM `##block` WHERE module_name=:module_name)"
		)->execute($args)->fetchOne();

		if (Auth::isSearchEngine()) {
			return null;
		}

		$main_menu_title = $this->getSetting('MP_PAGE_TITL', $this->getMenuTitle());

		if (file_exists(WT_MODULES_DIR . $this->getName() . '/themes/' . Theme::theme()->themeId() . '/')) {
			echo '<link rel="stylesheet" href="' . WT_MODULES_DIR . $this->getName() . '/themes/' . Theme::theme()->themeId() . '/style.css" type="text/css">';
		} else {
			echo '<link rel="stylesheet" href="' . WT_MODULES_DIR . $this->getName() . '/themes/webtrees/style.css" type="text/css">';
		}

		//-- main pages menu item
		$menu = new Menu(I18N::translate($main_menu_title), 'module.php?mod=' . $this->getName() . '&amp;mod_action=show&amp;pages_id=' . $default_block, $this->getName());
		$menu->addClass('menuitem', 'menuitem_hover', '');
		$already_added = [];
		foreach ($this->getMenupagesList() as $items) {
			$languages = $this->getBlockSetting($items->block_id, 'languages');
			if ((!$languages || in_array(WT_LOCALE, explode(',', $languages))) && $items->pages_access >= Auth::accessLevel($WT_TREE)) {
				if (strpos($items->pages_title, "#") !== FALSE) {
					$splitted = explode("#", $items->pages_title);
					if (array_search($splitted[0], $already_added) === FALSE) {
						$already_added[] = $splitted[0];
						$path = 'module.php?mod=' . $this->getName() . '&amp;mod_action=show&amp;pages_id=' . $items->block_id;
						$submenu = new Menu(I18N::translate($splitted[0]), $path, $this->getName() . '-' . $items->block_id);
						$menu->addSubmenu($submenu);
					}
				} else {
					$path = 'module.php?mod=' . $this->getName() . '&amp;mod_action=show&amp;pages_id=' . $items->block_id;
					$submenu = new Menu(I18N::translate($items->pages_title), $path, $this->getName() . '-' . $items->block_id);
					$menu->addSubmenu($submenu);
				}
			}
		}
		if (Auth::isAdmin()) {
			$submenu = new Menu(I18N::translate('Edit pages'), $this->getConfigLink(), $this->getName() . '-edit');
			$menu->addSubmenu($submenu);
		}
		return $menu;
	}

	// Extend Module
	public function modAction($mod_action) {
		switch($mod_action) {
		case 'show':
			$this->show();
			break;
		case 'admin_config':
			if (Filter::post('action') == 'update'  && Filter::checkCsrf()) {
				$this->setSetting('MP_PAGE_TITL', Filter::post('NEW_MP_PAGE_TITL'));
				$this->setSetting('MP_PAGE_DESC', Filter::post('NEW_MP_PAGE_DESC'));
			}
			$this->config();
			break;
		case 'admin_delete':
			$this->delete();
			$this->config();
			break;
		case 'admin_edit':
			$this->edit();
			break;
		case 'admin_movedown':
			$this->moveDown();
			$this->config();
			break;
		case 'admin_moveup':
			$this->moveUp();
			$this->config();
			break;
		default:
			http_response_code(404);
		}
	}

	// Action from the configuration page
	private function edit() {
		global $WT_TREE;
		$args = array();
		
		if (Filter::postBool('save') && Filter::checkCsrf()) {
			$block_id = Filter::post('block_id');
			
			if ($block_id) {
				$args['tree_id']     = Filter::post('gedcom_id');
				$args['block_order'] = (int)Filter::post('block_order');
				$args['block_id']    = $block_id;
				Database::prepare(
					"UPDATE `##block` SET gedcom_id=NULLIF(:tree_id, ''), block_order=:block_order WHERE block_id=:block_id"
				)->execute($args);
			} else {
				$args['tree_id']     = Filter::post('gedcom_id');
				$args['module_name'] = $this->getName();
				$args['block_order'] = (int)Filter::post('block_order');
				Database::prepare(
					"INSERT INTO `##block` (gedcom_id, module_name, block_order) VALUES (NULLIF(:tree_id, ''), :module_name, :block_order)"
				)->execute($args);
				$block_id = Database::getInstance()->lastInsertId();
			}
			$this->setBlockSetting($block_id, 'pages_title', Filter::post('pages_title'));
			$this->setBlockSetting($block_id, 'pages_content', Filter::post('pages_content')); // allow html
			$this->setBlockSetting($block_id, 'pages_access', Filter::post('pages_access'));
			$languages = array();
			foreach (I18N::installedLocales() as $locale) {
				if (Filter::postBool('lang_'.$locale->languageTag())) {
					$languages[] = $locale->languageTag();
				}
			}
			$this->setBlockSetting($block_id, 'languages', implode(',', $languages));
			$this->config();
		} else {
			$block_id = Filter::get('block_id');
			$controller = new PageController();
			$controller->restrictAccess(Auth::isEditor($WT_TREE));
			if ($block_id) {
				$controller->setPageTitle(I18N::translate('Edit page'));
				$items_title      = $this->getBlockSetting($block_id, 'pages_title');
				$items_content    = $this->getBlockSetting($block_id, 'pages_content');
				$items_access     = $this->getBlockSetting($block_id, 'pages_access');
				$args['block_id'] = $block_id;
				$block_order      = Database::prepare(
					"SELECT block_order FROM `##block` WHERE block_id=:block_id"
				)->execute($args)->fetchOne();
				$gedcom_id        = Database::prepare(
					"SELECT gedcom_id FROM `##block` WHERE block_id=:block_id"
				)->execute($args)->fetchOne();
			} else {
				$controller->setPageTitle(I18N::translate('Add page'));
				$items_title         = '';
				$items_content       = '';
				$items_access        = 1;
				$args['module_name'] = $this->getName();
				$block_order         = Database::prepare(
					"SELECT IFNULL(MAX(block_order)+1, 0) FROM `##block` WHERE module_name=:module_name"
				)->execute($args)->fetchOne();
				$gedcom_id           = $WT_TREE->getTreeId();
			}
			$controller->pageHeader();
			
			if (Module::getModuleByName('ckeditor')) {
				Module\CkeditorModule::enableEditor($controller);
			}
			?>
			
			<ol class="breadcrumb small">
				<li><a href="admin.php"><?php echo /* I18N: Do NOT translate. Part of webtrees core. */ I18N::translate('Control panel'); ?></a></li>
				<li><a href="admin_modules.php"><?php echo /* I18N: Do NOT translate. Part of webtrees core. */ I18N::translate('Module administration'); ?></a></li>
				<li><a href="module.php?mod=<?php echo $this->getName(); ?>&mod_action=admin_config"><?php echo I18N::translate($this->getTitle()); ?></a></li>
				<li class="active"><?php echo $controller->getPageTitle(); ?></li>
			</ol>

			<form class="form-horizontal" method="POST" action="#" name="pages" id="pagesForm">
				<?php echo Filter::getCsrf(); ?>
				<input type="hidden" name="save" value="1">
				<input type="hidden" name="block_id" value="<?php echo $block_id; ?>">
				<h3><?php echo I18N::translate('General'); ?></h3>
				
				<div class="form-group">
					<label class="control-label col-sm-3" for="pages_title">
						<?php echo I18N::translate('Title'); ?>
					</label>
					<div class="col-sm-9">
						<input
							class="form-control"
							id="pages_title"
							size="90"
							name="pages_title"
							required
							type="text"
							value="<?php echo Filter::escapeHtml($items_title); ?>"
							>
					</div>
				</div>
				<div class="form-group">
					<label class="control-label col-sm-3" for="pages_content">
						<?php echo I18N::translate('Content'); ?>
					</label>
					<div class="col-sm-9">
						<textarea
							class="form-control html-edit"
							id="pages_content"
							rows="10"
							cols="90"
							name="pages_content"
							required
							type="text">
								<?php echo Filter::escapeHtml($items_content); ?>
						</textarea>
					</div>
				</div>
				
				<h3><?php echo I18N::translate('Languages'); ?></h3>
				
				<div class="form-group">
					<label class="control-label col-sm-3" for="lang_*">
						<?php echo I18N::translate('Show this page for which languages?'); ?>
					</label>
					<div class="col-sm-9">
						<?php 
							$accepted_languages=explode(',', $this->getBlockSetting($block_id, 'languages'));
							foreach (I18N::activeLocales() as $locale) {
						?>
								<div class="checkbox">
									<label title="<?php echo $locale->languageTag(); ?>">
										<input type="checkbox" name="lang_<?php echo $locale->languageTag(); ?>" <?php echo in_array($locale->languageTag(), $accepted_languages) ? 'checked' : ''; ?> ><?php echo $locale->endonym(); ?>
									</label>
								</div>
						<?php 
							}
						?>
					</div>
				</div>
				
				<h3><?php echo I18N::translate('Visibility and Access'); ?></h3>
				
				<div class="form-group">
					<label class="control-label col-sm-3" for="block_order">
						<?php echo I18N::translate('Page position'); ?>
					</label>
					<div class="col-sm-9">
						<input
							class="form-control"
							id="position"
							name="block_order"
							size="3"
							required
							type="number"
							value="<?php echo Filter::escapeHtml($block_order); ?>"
						>
					</div>
					<span class="help-block col-sm-9 col-sm-offset-3 small text-muted">
						<?php 
							echo I18N::translate('This field controls the order in which the pages are displayed.'), '<br><br>', I18N::translate('You do not have to enter the numbers sequentially. If you leave holes in the numbering scheme, you can insert other pages later. For example, if you use the numbers 1, 6, 11, 16, you can later insert pages with the missing sequence numbers. Negative numbers and zero are allowed, and can be used to insert menu items in front of the first one.'), '<br><br>', I18N::translate('When more than one page has the same position number, only one of these pages will be visible.');
						?>
					</span>
				</div>
				<div class="form-group">
					<label class="control-label col-sm-3" for="block_order">
						<?php echo I18N::translate('Page visibility'); ?>
					</label>
					<div class="col-sm-9">
						<?php echo FunctionsEdit::selectEditControl('gedcom_id', Tree::getIdList(), I18N::translate('All'), $gedcom_id, 'class="form-control"'); ?>
					</div>
					<span class="help-block col-sm-9 col-sm-offset-3 small text-muted">
						<?php 
							echo I18N::translate('You can determine whether this page will be visible regardless of family tree, or whether it will be visible only to the current family tree.');
						?>
					</span>
				</div>
				<div class="form-group">
					<label class="control-label col-sm-3" for="pages_access">
						<?php echo I18N::translate('Access level'); ?>
					</label>
					<div class="col-sm-9">
						<?php echo FunctionsEdit::editFieldAccessLevel('pages_access', $items_access, 'class="form-control"'); ?>
					</div>
				</div>
				
				<div class="row col-sm-9 col-sm-offset-3">
					<button class="btn btn-primary" type="submit">
						<i class="fa fa-check"></i>
						<?php echo /* I18N: Do NOT translate. Part of webtrees core. */ I18N::translate('save'); ?>
					</button>
					<button class="btn" type="button" onclick="window.location='<?php echo $this->getConfigLink(); ?>';">
						<i class="fa fa-close"></i>
						<?php echo /* I18N: Do NOT translate. Part of webtrees core. */ I18N::translate('cancel'); ?>
					</button>
				</div>
			</form>
<?php
		}
	}

	private function delete() {
		global $WT_TREE;
		
		if (Auth::isManager($WT_TREE)) {
			$args             = array();
			$args['block_id'] = Filter::get('block_id');

			Database::prepare(
				"DELETE FROM `##block_setting` WHERE block_id = :block_id"
			)->execute($args);

			Database::prepare(
				"DELETE FROM `##block` WHERE block_id = :block_id"
			)->execute($args);
		} else {
			header('Location: ' . WT_BASE_URL);
			exit;
		}
	}

	private function moveUp() {
		global $WT_TREE;
		
		if (Auth::isManager($WT_TREE)) {
			$block_id         = Filter::get('block_id');
			$args             = array();
			$args['block_id'] = $block_id;

			$block_order = Database::prepare(
				"SELECT block_order FROM `##block` WHERE block_id = :block_id"
			)->execute($args)->fetchOne();

			$args                = array();
			$args['module_name'] = $this->getName();
			$args['block_order'] = $block_order;
			
			$swap_block = Database::prepare(
				"SELECT block_order, block_id".
				" FROM `##block`".
				" WHERE block_order = (".
				"  SELECT MAX(block_order) FROM `##block` WHERE block_order < :block_order AND module_name = :module_name".
				" ) AND module_name = :module_name".
				" LIMIT 1"
			)->execute($args)->fetchOneRow();
			if ($swap_block) {
				$args                = array();
				$args['block_id']    = $block_id;
				$args['block_order'] = $swap_block->block_order;
				Database::prepare(
					"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
				)->execute($args);
				
				$args                = array();
				$args['block_order'] = $block_order;
				$args['block_id']    = $swap_block->block_id;
				Database::prepare(
					"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
				)->execute($args);
			}
		} else {
			header('Location: ' . WT_BASE_URL);
			exit;
		}
	}

	private function moveDown() {
		global $WT_TREE;
		
		if (Auth::isManager($WT_TREE)) {
			$block_id         = Filter::get('block_id');
			$args             = array();
			$args['block_id'] = $block_id;

			$block_order = Database::prepare(
				"SELECT block_order FROM `##block` WHERE block_id = :block_id"
			)->execute($args)->fetchOne();

			$args                = array();
			$args['module_name'] = $this->getName();
			$args['block_order'] = $block_order;
			
			$swap_block = Database::prepare(
				"SELECT block_order, block_id".
				" FROM `##block`".
				" WHERE block_order = (".
				"  SELECT MIN(block_order) FROM `##block` WHERE block_order > :block_order AND module_name = :module_name".
				" ) AND module_name = :module_name".
				" LIMIT 1"
			)->execute($args)->fetchOneRow();
			if ($swap_block) {
				$args                = array();
				$args['block_id']    = $block_id;
				$args['block_order'] = $swap_block->block_order;
				Database::prepare(
					"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
				)->execute($args);
				
				$args                = array();
				$args['block_order'] = $block_order;
				$args['block_id']    = $swap_block->block_id;
				Database::prepare(
					"UPDATE `##block` SET block_order = :block_order WHERE block_id = :block_id"
				)->execute($args);
			}
		} else {
			header('Location: ' . WT_BASE_URL);
			exit;
		}
	}

	private function show() {
		global $controller, $WT_TREE;
		$items_id = Filter::get('pages_id');
		$controller = new PageController();
		$items_list = $this->getPagesList();

		$multi_level = FALSE;
		foreach ($items_list as $items) {
			if ($items_id==$items->block_id) {
				$multi_level = strpos($items->pages_title, "#");
				if ($multi_level !== FALSE) {
					$splitted = explode("#", $items->pages_title);
					$title = $splitted[0]; 
				} else 
					$title = $items->pages_title; 
			}
		}

		$controller->setPageTitle(I18N::translate(I18N::translate($this->getSetting('MP_PAGE_TITL', I18N::translate($title)))))
			->pageHeader();
		// HTML common to all pages
		$html = '<div id="pages-container">' . 
					'<h2>'.I18N::translate($controller->getPageTitle()).'</h2>'.
					'<h3>'.I18N::translate($this->getSetting('MP_PAGE_DESC', '')).'</h3>'.
					'<br>'.
 				'<div style="clear:both;"></div>' .
				'<div id="pages_tabs" class="ui-tabs ui-widget ui-widget-content ui-corner-all">' .
				'<ul class="ui-tabs-nav ui-helper-reset ui-helper-clearfix ui-widget-header ui-corner-all">';
		foreach ($items_list as $items) {
			$title_to_display = $items->pages_title;
			if ($multi_level !== FALSE) {
				$splitted = explode("#", $items->pages_title);
				if ($title != $splitted[0]) {
					continue;
				} else
					$title_to_display = $splitted[1];
			} else continue;

			$languages = $this->getBlockSetting($items->block_id, 'languages');
			if ((!$languages || in_array(WT_LOCALE, explode(',', $languages))) && $items->pages_access >= Auth::accessLevel($WT_TREE)) {
				$html .= '<li class="ui-state-default ui-corner-top' . ($items_id==$items->block_id ? ' ui-tabs-active ui-state-active' : '') . '">' .
					'<a href="module.php?mod=' . $this->getName() . '&amp;mod_action=show&amp;pages_id=' . $items->block_id . '" class="ui-tabs-anchor">' .
					'<span title="' . str_replace("{@PERC@}", "%", I18N::translate(str_replace("%", "{@PERC@}", $title_to_display))) . '">' . str_replace("{@PERC@}", "%", I18N::translate(str_replace("%", "{@PERC@}", $title_to_display))) . '</span></a></li>';
			}
		}
		$html .= '</ul>';
		$html .= '<div id="outer_pages_container" style="padding: 1em;">';
		foreach ($items_list as $items) {
			$languages = $this->getBlockSetting($items->block_id, 'languages');
			if ((!$languages || in_array(WT_LOCALE, explode(',', $languages))) && $items_id == $items->block_id && $items->pages_access >= Auth::accessLevel($WT_TREE)) {
				$items_content = str_replace("{@PERC@}", "%", I18N::translate(str_replace("%", "{@PERC@}", $items->pages_content)));
			}
		}
		if (isset($items_content)){
			$html .= $items_content;
		} else {
			$html .= I18N::translate('No content found for current access level and language');
		}
		$html .= '</div>'; //close outer_pages_container
		$html .= '</div>'; //close pages_tabs
		$html .= '</div>'; //close pages-container
		$html .= '<script>document.onreadystatechange = function () {if (document.readyState == "complete") {$(".pages-accordion").accordion({heightStyle: "content", collapsible: true});}}</script>';
		echo $html;
	}

	private function config() {
		global $WT_TREE;
		
		$controller = new PageController();
		$controller
			->restrictAccess(Auth::isManager($WT_TREE))
			->setPageTitle($this->getTitle())
			->pageHeader();

		$MP_PAGE_TITL = $this->getSetting('MP_PAGE_TITL', I18N::translate('Custom menu'));
		$MP_PAGE_DESC = $this->getSetting('MP_PAGE_DESC', '');
		$args                = array();
		$args['module_name'] = $this->getName();
		$args['tree_id']     = $WT_TREE->getTreeId();
		$items = Database::prepare(
			"SELECT block_id, block_order, gedcom_id, bs1.setting_value AS pages_title, bs2.setting_value AS pages_content" .
			" FROM `##block` b" .
			" JOIN `##block_setting` bs1 USING (block_id)" .
			" JOIN `##block_setting` bs2 USING (block_id)" .
			" WHERE module_name = :module_name" .
			" AND bs1.setting_name='pages_title'" .
			" AND bs2.setting_name='pages_content'" .
			" AND IFNULL(gedcom_id, :tree_id) = :tree_id" .
			" ORDER BY block_order"
		)->execute($args)->fetchAll();

		unset($args['tree_id']);
		$min_block_order = Database::prepare(
			"SELECT MIN(block_order) FROM `##block` WHERE module_name = :module_name"
		)->execute($args)->fetchOne();

		$max_block_order = Database::prepare(
			"SELECT MAX(block_order) FROM `##block` WHERE module_name = :module_name"
		)->execute($args)->fetchOne();
		?>
		<style>
			.text-left-not-xs, .text-left-not-sm, .text-left-not-md, .text-left-not-lg {
				text-align: left;
			}
			.text-center-not-xs, .text-center-not-sm, .text-center-not-md, .text-center-not-lg {
				text-align: center;
			}
			.text-right-not-xs, .text-right-not-sm, .text-right-not-md, .text-right-not-lg {
				text-align: right;
			}
			.text-justify-not-xs, .text-justify-not-sm, .text-justify-not-md, .text-justify-not-lg {
				text-align: justify;
			}

			@media (max-width: 767px) {
				.text-left-not-xs, .text-center-not-xs, .text-right-not-xs, .text-justify-not-xs {
					text-align: inherit;
				}
				.text-left-xs {
					text-align: left;
				}
				.text-center-xs {
					text-align: center;
				}
				.text-right-xs {
					text-align: right;
				}
				.text-justify-xs {
					text-align: justify;
				}
			}
			@media (min-width: 768px) and (max-width: 991px) {
				.text-left-not-sm, .text-center-not-sm, .text-right-not-sm, .text-justify-not-sm {
					text-align: inherit;
				}
				.text-left-sm {
					text-align: left;
				}
				.text-center-sm {
					text-align: center;
				}
				.text-right-sm {
					text-align: right;
				}
				.text-justify-sm {
					text-align: justify;
				}
			}
			@media (min-width: 992px) and (max-width: 1199px) {
				.text-left-not-md, .text-center-not-md, .text-right-not-md, .text-justify-not-md {
					text-align: inherit;
				}
				.text-left-md {
					text-align: left;
				}
				.text-center-md {
					text-align: center;
				}
				.text-right-md {
					text-align: right;
				}
				.text-justify-md {
					text-align: justify;
				}
			}
			@media (min-width: 1200px) {
				.text-left-not-lg, .text-center-not-lg, .text-right-not-lg, .text-justify-not-lg {
					text-align: inherit;
				}
				.text-left-lg {
					text-align: left;
				}
				.text-center-lg {
					text-align: center;
				}
				.text-right-lg {
					text-align: right;
				}
				.text-justify-lg {
					text-align: justify;
				}
			}
		</style>
		
		<ol class="breadcrumb small">
			<li><a href="admin.php"><?php echo /* I18N: Do NOT translate. Part of webtrees core. */ I18N::translate('Control panel'); ?></a></li>
			<li><a href="admin_modules.php"><?php echo /* I18N: Do NOT translate. Part of webtrees core. */ I18N::translate('Module administration'); ?></a></li>
			<li class="active"><?php echo $controller->getPageTitle(); ?></li>
		</ol>
		<div class="row">
			<div class="col-sm-4 col-xs-12">
				<form class="form">
					<label for="ged" class="sr-only">
						<?php echo I18N::translate('Family tree'); ?>
					</label>
					<input type="hidden" name="mod" value="<?php echo  $this->getName(); ?>">
					<input type="hidden" name="mod_action" value="admin_config">
					<div class="col-sm-9 col-xs-9" style="padding:0;">
						<?php echo FunctionsEdit::selectEditControl('ged', Tree::getNameList(), null, $WT_TREE->getName(), 'class="form-control"'); ?>
					</div>
					<div class="col-sm-3" style="padding:3px;">
						<input type="submit" class="btn btn-primary" value="<?php echo I18N::translate('show'); ?>">
					</div>
				</form>
			</div>
			<span class="visible-xs hidden-sm hidden-md hidden-lg" style="display:block;"></br></br></span>
			<div class="col-sm-4 text-center text-left-xs col-xs-12">
				<p>
					<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_edit" class="btn btn-primary">
						<i class="fa fa-plus"></i>
						<?php echo I18N::translate('Add page'); ?>
					</a>
				</p>
			</div>
		</div>
		
		<table class="table table-bordered table-condensed">
			<thead>
				<tr>
					<th class="col-sm-2"><?php echo I18N::translate('Position'); ?></th>
					<th class="col-sm-3"><?php echo I18N::translate('Title'); ?></th>
					<th class="col-sm-1" colspan=4><?php echo I18N::translate('Controls'); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ($items as $item): ?>
				<tr>
					<td>
						<?php echo $item->block_order, ', ';
						if ($item->gedcom_id == null) {
							echo I18N::translate('All');
						} else {
							echo Tree::findById($item->gedcom_id)->getTitleHtml();
						} ?>
					</td>
					<td>
						<?php echo Filter::escapeHtml(I18N::translate($item->pages_title)); ?>
					</td>
					<td class="text-center">
						<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_edit&amp;block_id=<?php echo $item->block_id; ?>">
							<div class="icon-edit">&nbsp;</div>
						</a>
					</td>
					<td class="text-center">
						<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_moveup&amp;block_id=<?php echo $item->block_id; ?>">
							<?php
								if ($item->block_order == $min_block_order) {
									echo '&nbsp;';
								} else {
									echo '<div class="icon-uarrow">&nbsp;</div>';
								} 
							?>
						</a>
					</td>
					<td class="text-center">
						<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_movedown&amp;block_id=<?php echo $item->block_id; ?>">
							<?php
								if ($item->block_order == $max_block_order) {
									echo '&nbsp;';
								} else {
									echo '<div class="icon-darrow">&nbsp;</div>';
								} 
							?>
						</a>
					</td>
					<td class="text-center">
						<a href="module.php?mod=<?php echo $this->getName(); ?>&amp;mod_action=admin_delete&amp;block_id=<?php echo $item->block_id; ?>"
							onclick="return confirm('<?php echo I18N::translate('Are you sure you want to delete this page?'); ?>');">
							<div class="icon-delete">&nbsp;</div>
						</a>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
<?php
	}

	// Return the list of pages
	private function getPagesList() {
		global $WT_TREE;
		
		$args                = array();
		$args['module_name'] = $this->getName();
		$args['tree_id']     = $WT_TREE->getTreeId();
		return Database::prepare(
			"SELECT block_id, bs1.setting_value AS pages_title, bs2.setting_value AS pages_access, bs3.setting_value AS pages_content" .
			" FROM `##block` b" .
			" JOIN `##block_setting` bs1 USING (block_id)" .
			" JOIN `##block_setting` bs2 USING (block_id)" .
			" JOIN `##block_setting` bs3 USING (block_id)" .
			" WHERE module_name = :module_name" .
			" AND bs1.setting_name='pages_title'" .
			" AND bs2.setting_name='pages_access'" .
			" AND bs3.setting_name='pages_content'" .
			" AND (gedcom_id IS NULL OR gedcom_id = :tree_id)" .
			" ORDER BY block_order"
		)->execute($args)->fetchAll();
	}
	
	// Return the list of pages for menu
	private function getMenupagesList() {
		global $WT_TREE;
		
		$args                = array();
		$args['module_name'] = $this->getName();
		$args['tree_id']     = $WT_TREE->getTreeId();
		return Database::prepare(
			"SELECT block_id, bs1.setting_value AS pages_title, bs2.setting_value AS pages_access" .
			" FROM `##block` b" .
			" JOIN `##block_setting` bs1 USING (block_id)" .
			" JOIN `##block_setting` bs2 USING (block_id)" .
			" WHERE module_name = :module_name" .
			" AND bs1.setting_name='pages_title'" .
			" AND bs2.setting_name='pages_access'" .
			" AND (gedcom_id IS NULL OR gedcom_id = :tree_id)" .
			" ORDER BY block_order"
		)->execute($args)->fetchAll();
	}
}
return new MorficPagesModule;