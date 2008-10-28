<?php

class HistoryComponent extends Object {

  /**
   * Other components this component uses
   *
   * @var array
   */
	var $components = array('Session');

	/**
	 * Default settings for the component, overrideable by Settings.history array
	 * in $config
	 *
	 * 'session_key' is a string for the key in the Session for storing history data
	 * Note this will have 2 further keys, site and admin, for storing the history
	 * stacks
	 *
	 * 'save_site_history' is a boolean controlling whether the uri history on
	 * the front end should be recorded
	 *
	 * 'save_admin_history' is a boolean controlling whether the uri history on
	 * the back end / admin / cms (as defined by Routing.admin) should be recorded
	 *
	 * @var array
	 */
	var $settings = array(
    'session_key' => 'History',
    'save_site_history' => false,
    'save_admin_history' => false,
    'default_title' => 'Previous page',
  );

  /**
   * Called automatically after controller beforeFilter
   * Stores refernece to controller object
   * Merges Settings.history array in $config with default settings
   *
   * @param object $controller
   */
	function startup(&$controller) {
		$this->Controller = $controller;
		$this->settings = array_merge($this->settings, Configure::read('Settings.history'));
	}

	/**
	 * Saves the uri and page title of the current page in a stack in the Session.
	 * Separate stacks for front end and back end / admin / cms (as defined by
	 * Routing.admin)
	 *
	 */
	function beforeRender() {

	  $uri = null;
	  if (isset($_SERVER['REQUEST_URI'])) {
	    $uri = $_SERVER['REQUEST_URI'];
	  }

	  if (!is_string($uri) || empty($uri)) {
	    return;
	  }

	  if (isset($this->Controller->params['bare'])) {
	    return;
	  }

	  $siteOrAdmin = Configure::read('Runtime.site_or_admin');


	  if ($siteOrAdmin == 'site' && !$this->settings['save_site_history']) {
	    return;
	  } elseif ($siteOrAdmin == 'admin' && !$this->settings['save_admin_history']) {
	    return;
	  }

	  if ($this->Session->check($this->settings['session_key'].'.'.$siteOrAdmin)) {
	    $history = $this->Session->read($this->settings['session_key'].'.'.$siteOrAdmin);
	  } else {
	    $history = array();
	  }

	  if (isset($history[0]['uri']) && $history[0]['uri'] == $uri) {
	    return;
	  } elseif (isset($history[1]['uri']) && $history[1]['uri'] == $uri) {
	    array_shift($history);
	  } else {

  	  if (!empty($this->Controller->pageTitle)) {
  	    $title = $this->Controller->pageTitle;
  	  } else {
        $title = __('Previous page', true);
  	  }

	    array_unshift($history, array('uri' => $uri, 'title' => $title));
	  }

	  $this->Session->write($this->settings['session_key'].'.'.$siteOrAdmin, $history);

	}

	/**
	 * Redirect to previous page
	 *
	 * @param integer $index Index in the stack to redirect to. Default is -1,
	 * i.e. go back to the page before the last one. Useful for after adding or
	 * editing something, you want to go back to index. Use 0 if in an action that
	 * never renders anything, e.g. delete.
	 * @param mixed $default Could be an array or string - anything that
	 * Controller::redirect will accept. Used if index of history stack is
	 * unavailable for any reason.
	 */
	function back($index = -1, $default = null) {

	  $index = abs($index);

		if ($this->Session->check($this->settings['session_key'].'.'.Configure::read('Runtime.site_or_admin').'.'.$index.'.uri')) {

			$redirect = $this->Session->read($this->settings['session_key'].'.'.Configure::read('Runtime.site_or_admin').'.'.$index.'.uri');

		} elseif ($default) {

		  $redirect = $default;

		} else {

		  $redirect = array('action' => 'index');

		  if (Configure::read('Runtime.site_or_admin') == 'admin') {
		    $redirect['admin'] = true;
		  }

		}

		$this->Controller->redirect($redirect);

	}

}

?>
