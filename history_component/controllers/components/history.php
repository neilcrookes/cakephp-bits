<?php
/**
 * History Component
 *
 * Manages a page history stack or First In, Last Out (FILO) buffer, in the
 * user's session.
 *
 * Separate stacks for site and admin parts of a site.
 *
 * Stacks are automatically managed in beforeRender callback (beforeRender is
 * used because Controller::pageTitle has been set by then whereas it hasn't in
 * beforeFilter).
 *
 * Automatically sets a view variable with title and uri of previous page for
 * creating "Back to ..." links in views.
 *
 * Provides back() method which can redirect to any previous page. In a typical
 * app you might have an index action from where you can edit a record - after
 * saving the record you want to go back to the index action, which is at
 * position 1 in the stack. Whereas after deleting a record you want to go back
 * to the index but in this case it's at position 0 because there was no render
 * from the delete action.
 *
 * Back() can also accept a default if likely to be used in an action where
 * there may not be a previous page in the session stack, for example if the
 * user came from another site.
 *
 * @author Neil Crookes <neil@neilcrookes.com>
 * @link http://www.neilcrookes.com
 * @copyright (c) 2008 Neil Crookes
 * @license MIT License - http://www.opensource.org/licenses/mit-license.php
 * @link http://github.com/neilcrookes/cakephp/tree/master
 *
 */
class HistoryComponent extends Object {

  /**
   * Other components this component uses
   *
   * @var array
   */
  var $components = array('Session', 'RequestHandler');

  /**
   * Default settings for the component, overrideable by settings declared when
   * including HistoryComponent in Controller's components array, e.g.
   * var $components = array('History' => array('session_key' => 'MyHistory'));
   *
   * session_key is a string for the key in the Session for storing history
   * data Note this will have 2 further keys, site and admin, for storing the
   * history stacks
   *
   * save_site_history is a boolean controlling whether the uri history on the
   * front end should be recorded
   *
   * save_admin_history is a boolean controlling whether the uri history on the
   * back end / admin / cms (as defined by Routing.admin) should be recorded
   *
   * default_title is a string (that is translated) that is used if the
   * Controller::pageTitle property is not set
   *
   * previous_page_var is a string that is used for the view var containing the
   * previous page info e.g.
   *   array(
   *     'title' => 'previous page titile',
   *     'uri' => '/path/to/previous/page',
   *   )
   *
   * @var array
   */
  var $_defaults = array(
    'session_key' => 'History',
    'save_site_history' => true,
    'save_admin_history' => true,
    'default_title' => 'Previous page',
    'previous_page_var' => 'previousPage',
  );

  /**
   * Stores settings
   *
   * @var array
   */
  var $settings;

  /**
   * Stores whether current request is in the admin or site part of the app set
   * up in initialize method
   *
   * @var string 'site' or 'admin'
   */
  var $siteOrAdmin;

  /**
   * Called automatically before controller beforeFilter. Stores reference to
   * controller object. Merges passed config array with default settings.
   *
   * @param object $controller
   * @param array $config
   */
  function initialize(&$controller, $config = array()) {

    $this->Controller = $controller;

    if (!is_array($config)) {
      $config = array($config);
    }

    $this->settings = array_merge($this->_defaults, $config);

    $this->siteOrAdmin();

  }

  /**
   * Called automatically after controller beforeFilter
   *
   * @param object $controller
   */
  function startup(&$controller) {
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

    if ($this->RequestHandler->isAjax()) {
      return;
    }

    // If configured not to save admin or site history, return
    if ($this->siteOrAdmin == 'site' && !$this->settings['save_site_history']) {
      return;
    } elseif ($this->siteOrAdmin == 'admin' && !$this->settings['save_admin_history']) {
      return;
    }

    // Get history from session
    if ($this->Session->check($this->settings['session_key'].'.'.$this->siteOrAdmin)) {
      $history = $this->Session->read($this->settings['session_key'].'.'.$this->siteOrAdmin);
    } else {
      $history = array();
    }

    if (isset($history[0]['uri']) && $history[0]['uri'] == $uri) {

      /**
       * If current uri is same as last uri, i.e. page has been refreshed, do
       * nothing
       */

    } elseif (isset($history[1]['uri']) && $history[1]['uri'] == $uri) {

      /**
       * If current uri is same as last but one uri, i.e. user has gone back to
       * previous page, remove the last page from the stack
       */
      array_shift($history);

    } else {

      /**
       * Add another page into the stack
       */
      if (!empty($this->Controller->pageTitle)) {
        $title = $this->Controller->pageTitle;
      } else {
        $title = __($this->settings['default_title'], true);
      }

      array_unshift($history, array('uri' => $uri, 'title' => $title));

    }

    /**
     * Update stack in session
     */
    $this->Session->write($this->settings['session_key'].'.'.$this->siteOrAdmin, $history);

    /**
     * Set previous page title and link view variable
     */
    if (isset($history[1])) {

      $this->Controller->set($this->settings['previous_page_var'], $history[1]);

    }

  }

  /**
   * Redirect to previous page
   *
   * @param integer $index Index in the stack to redirect to. Default is -1,
   * i.e. go back to the page before the last one. Useful for after adding or
   * editing something, you want to go back to index. Use 0 if in an action that
   * never renders anything, e.g. delete, or toggle field values etc.
   * @param mixed $default Could be an array or string - anything that
   * Controller::redirect will accept. Used if index of history stack is
   * unavailable for any reason, e.g. previous page was another site.
   * @param boolean $return Determines whether to redirect or return
   */
  function back($index = -1, $default = null, $return = false) {

    /**
     * index param can be negative, but stack offset isn't, so make it unsigned
     */
    $index = abs($index);

    if ($this->Session->check($this->settings['session_key'].'.'.$this->siteOrAdmin.'.'.$index.'.uri')) {

      /**
       * Redirect is from the stack
       */
      $redirect = $this->Session->read($this->settings['session_key'].'.'.$this->siteOrAdmin.'.'.$index.'.uri');

    } elseif ($default) {

      /**
       * Stack offset unavailable so use default
       */
      $redirect = $default;

    } else {

      /**
       * Default unspecified so go back to current controller's index action
       */
      $redirect = array('action' => 'index');

      if ($this->siteOrAdmin == 'admin') {
        $redirect[Configure::read('Routing.admin')] = true;
      }

    }

    /**
     * Redirect or return redirect params
     */
    if ($return) {

      return $redirect;

    } else {

      $this->Controller->redirect($redirect);

    }

  }

  /**
   * Sets HistoryComponent::siteOrAdmin property to 'site' or 'admin'
   *
   * @return string 'site' or 'admin'
   */
  function siteOrAdmin() {

    if (Configure::read('Routing.admin')
    && isset($this->Controller->params[Configure::read('Routing.admin')])
    && $this->params[Configure::read('Routing.admin')] = Configure::read('Routing.admin')) {
      $this->siteOrAdmin = 'admin';
    } else {
      $this->siteOrAdmin = 'site';
    }

    return $this->siteOrAdmin;

  }

}

?>