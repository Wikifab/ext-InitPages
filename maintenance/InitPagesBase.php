<?php
namespace InitPages;
/**
 * scritp to initialise specifics wikifab pages, such as forms, properties, and home page
 *
 * @file
 * @ingroup Maintenance
 */
require_once $IP . '/maintenance/Maintenance.php';

use ContentHandler;
use Exception;
use Maintenance;
use Title;
use User;
use UserArray;
use WikiPage;

/**
 * Maintenance script to init Site (create all forms, template, ...)
 *
 * @ingroup Maintenance
 */
class InitPagesBase extends Maintenance {

	protected $nsprefixes = [];

	public function __construct() {
		parent::__construct ();
		$this->mDescription = "Init pages";
		$this->addOption ( 'setHomePage', "Set the wiki home page", false, false );
		$this->addOption ( 'force', "force edit of existing pages", false, false );
	}

	protected function getUpdateKey() {
		return 'initialise_Wikifab_Pages';
	}

	protected function updateSkippedMessage() {
		return 'Wikifab pages are allready setup';
	}

	public function execute() {

		$setWikifabHomePage = $this->getOption ( 'setHomePage' );
		$force = $this->getOption ( 'force' ) ? true : false;

		$homePageFile = [
				'fr' => 'Accueil.txt',
				'en' => 'Main_Page.txt',
				'int' => 'Accueil.txt'
		];

		$pagelist = $this->getPageListToCreate ();

		echo "Setting Up wikifab pages ...\n";


		if ($setWikifabHomePage) {
			echo "Setting wiki home page $setWikifabHomePage\n";

			$ret = Title::newMainPage();
			$pageTitle = $ret->getText();
			$title = $this->getPageName ( $pageTitle );
			$content = $this->getPageContent ( $homePageFile['int']);
			$this->createPage ( $title, $content, true);

		} else {
			echo "No Setting wiki home page\n";
		}

		foreach ( $pagelist as $page ) {
			if ($page == $homePageFile) {
				continue;
			}
			$title = $this->getPageName ( $page );
			$content = $this->getPageContent ($page);
			$this->createPage ( $title, $content , $force);
		}
	}

	/**
	 * Get a WikiPage object from a title string, if possible.
	 *
	 * @param string $titleName
	 * @param bool|string $load
	 *        	Whether load the object's state from the database:
	 *        	- false: don't load (if the pageid is given, it will still be loaded)
	 *        	- 'fromdb': load from a slave database
	 *        	- 'fromdbmaster': load from the master database
	 * @return WikiPage
	 */
	protected function getPage($titleName) {
		$titleObj = Title::newFromText ( $titleName );
		if (! $titleObj || $titleObj->isExternal ()) {
			trigger_error ( 'Fail to get title ' . $titleName, E_USER_WARNI );
			return false;
		}
		if (! $titleObj->canExist ()) {
			trigger_error ( 'Title cannot be created ' . $titleName, E_USER_WARNING );
			return false;
		}
		$pageObj = WikiPage::factory ( $titleObj );

		return $pageObj;
	}
	protected function getAdminUser() {
		// get Admin user : (take the first user created)
		$dbr = wfGetDB ( DB_SLAVE );
		$res = $dbr->select ( 'user', User::selectFields (), array (), __METHOD__, array (
				'LIMIT' => 1,
				'ORDER BY' => 'user_id'
		) );
		$users = UserArray::newFromResult ( $res );
		$user = $users->current ();

		return $user;
	}
	protected function createPage($pageName, $text, $force = false) {

		global $wginitPagesNotOverwrite;

		$wikipage = $this->getPage ( $pageName );

		if ($wikipage->exists () && ! $force) {
			echo "page $pageName allready exists.\n";
			return false;
		}

		if ( !is_null($wginitPagesNotOverwrite) && in_array( $wikipage->getTitle()->getPrefixedDBKey(), $wginitPagesNotOverwrite) && $wikipage->exists () ) {
			return false;
		}

		$user = $this->getAdminUser ();

		$this->customPropertiesFetchData($wikipage, $text);

		//$this->removeLanguageTagIfTranslateNotLoaded($wikipage, $text);

		$content = ContentHandler::makeContent( $text, $wikipage->getTitle() );
		$result = $wikipage->doEditContent( $content, 'init wikifab pages', $flags = 0, $baseRevId = false, $user );

		if ($result->isOK ()) {
			echo "page $pageName successfully created.\n";
			return true;
		} else {
			echo $result->getWikiText ();
		}

		return false;
	}

	// remove <languages /> if Translate module is not loaded
	private function removeLanguageTagIfTranslateNotLoaded($wikipage, &$text) {

		if ( defined( 'TRANSLATE_VERSION' ) ) {
			return;
		}

		$title = $wikipage->getTitle()->getText();
		$namespace = $wikipage->getTitle()->getNamespace();

		if ( ( $title == 'Tuto details' || $title == 'Item' || $title == 'Group details' ) && $namespace == NS_TEMPLATE && $wikipage->exists () ) {

			$nativeData = $wikipage->getContent()->getNativeData(); // the original text

			$search_pattern = '/<languages \/>/s';

			$replace = '';

			$res = preg_replace($search_pattern, $replace, $text);

			if ($res) $text = $res;
		}
	}

	private function customPropertiesFetchData($wikipage, &$text) {

		$title = $wikipage->getTitle()->getText();
		$namespace = $wikipage->getTitle()->getNamespace();
		if ( ( $title == 'DokitPage' || $title == 'Tutorial' ) && $namespace == PF_NS_FORM && $wikipage->exists () ) {

			$nativeData = $wikipage->getContent()->getNativeData(); // the original text

			$types = ['CHECKBOXES', 'DROPDOWN', 'TEXT'];

			foreach ($types as $type) {

				$search_pattern = '/<!-- START ' . strtoupper($type) . ' ADMIN PROPERTY LIST -->(.*)<!--END ' . strtoupper($type) . ' ADMIN PROPERTY LIST -->/s';

				$ret = preg_match( $search_pattern, $nativeData, $matches );

				if ($ret) {
					$replace = '<!-- START ' . strtoupper($type) . ' ADMIN PROPERTY LIST -->';
					$replace .= $matches[1];
					$replace .= '<!--END ' . strtoupper($type) . ' ADMIN PROPERTY LIST -->';

					$text = preg_replace($search_pattern, $replace, $text);
				}
			}

		} elseif ( $title == 'Tuto Details' && $namespace == NS_TEMPLATE && $wikipage->exists ()) {

			$nativeData = $wikipage->getContent()->getNativeData(); // the original text

			$types = ['WEB', 'PRINT'];

			foreach ($types as $type) {

				$search_pattern = '/<!-- START ADMIN ' . strtoupper($type) . ' PROPERTY LIST -->(.*)<!--END ADMIN ' . strtoupper($type) . ' PROPERTY LIST -->/s';

				$ret = preg_match( $search_pattern, $nativeData, $matches );

				if ($ret) {
					$replace = '<!-- START ADMIN ' . strtoupper($type) . ' PROPERTY LIST -->';
					$replace .= $matches[1];
					$replace .= '<!--END ADMIN ' . strtoupper($type) . ' PROPERTY LIST -->';

					$text = preg_replace($search_pattern, $replace, $text);
				}
			}

		}
	}

	protected function getPageName($page) {
		$page = str_replace ( 'Form_', 'Form:', $page );
		$page = str_replace ( 'Property_', 'Property:', $page );
		if (strpos($page,'Template_') == 0) {
			$page = str_replace ( 'Template_', 'Template:', $page );
		}
		$page = str_replace ( 'Category_', 'Category:', $page );
		$page = str_replace ( 'Dokit_', 'Dokit:', $page );
		$page = str_replace ( 'Help_', 'Help:', $page );
		$page = str_replace ( 'MediaWiki_', 'Mediawiki:', $page );
		$page = str_replace ( 'Module_', 'Module:', $page );
		$page = str_replace ( 'Project_', 'Project:', $page );
		$page = str_replace ( 'Widget_', 'Widget:', $page );
		$page = str_replace ( '_', ' ', $page );
		$page = str_replace ( '.txt', '', $page );

		foreach ($this->nsprefixes as $key => $value) {
			if (substr($page, 0, strlen($key)) == $key) {
				$page = $value . substr($page, strlen($key)) ;
			}
		}
		// translate suffix : */en
		if (preg_match("/ ([a-z]{2})$/", $page, $matches)) {
			$page = substr($page, 0, strlen($page)-3) . '/' . $matches[1];
		}

		return $page;
	}
	protected function getPagesDirs() {
		return [
		];
	}
	protected function getPageContent($page) {
		$dirs = $this->getPagesDirs();

		foreach ($dirs as $dir) {
			if (file_exists($dir . '/' . $page)) {
				return file_get_contents ( $dir . '/' . $page );
			}
		}

		throw new Exception('File not found : ' . $page);
	}
	protected function getPageListToCreate() {
		$result = [ ];

		$dirs = $this->getPagesDirs();
		foreach ($dirs as $dir) {
			$files = scandir ( $dir );
			foreach ( $files as $file ) {
				if (preg_match ( '/^([a-zA-Z_0-9\-àéèç])+\.txt$/', $file )) {
					$result[$file] = $file;
				}
			}
		}
		return $result;
	}
}

