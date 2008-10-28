<?php
App::import('Controller', 'AppController');

class HistoryTestController extends AppController {

	var $testUrl = null;

	function redirect($url, $status = null, $exit = true) {
		$this->testUrl = Router::normalize($url);
		return false;
	}
}

class HistoryComponentTestCase extends CakeTestCase {

  var $defaultSettings;
  var $thistoryKey;
  var $defaultTitle;

  function setUp() {
    $this->Controller = new HistoryTestController();
    $this->Controller->Component->init($this->Controller);
    $this->defaultSettings = Configure::read('Settings.history');
    $this->historyKey = $this->defaultSettings['session_key'];
    $this->defaultTitle = $this->defaultSettings['default_title'];
  }

  function testStartup() {
    $expected = $this->defaultSettings;
    $this->Controller->History->startup($this->Controller);
    $this->assertEqual($this->Controller->History->settings, $expected);
  }

  function testBeforeRender() {

    $this->Controller->History->startup($this->Controller);

    $this->Controller->Session->del($this->historyKey);

    Configure::write('Runtime.site_or_admin', 'site');

    /**
     * test save with no uri
     */
    unset($_SERVER['REQUEST_URI']);
    $this->Controller->pageTitle = null;
    $this->Controller->History->beforeRender();
    $this->assertFalse($this->Controller->Session->check($this->historyKey));

    /**
     * test save with empty uri
     */
    $_SERVER['REQUEST_URI'] = '';
    $this->Controller->History->beforeRender();
    $this->assertFalse($this->Controller->Session->check($this->historyKey));

    /**
     * test save with valid uri
     */
    $uri1 = '/controller1/action1/param1/param2/named1:val1/named2:val2?query=string&query2=string2';
    $expected[Configure::read('Runtime.site_or_admin')][] = array('uri' => $uri1, 'title' => __($this->defaultTitle, true));
    $_SERVER['REQUEST_URI'] = $uri1;
    $this->Controller->History->beforeRender();
    $this->assertEqual($this->Controller->Session->read($this->historyKey), $expected);

    /**
     * test save with duplicate uri, simulates page refresh, ensure stack is the
     * same as previous
     */
    $this->Controller->History->beforeRender();
    $this->assertEqual($this->Controller->Session->read($this->historyKey), $expected);

    /**
     * test new uri gets pushed onto front of stack
     */
    $uri2 = '/controller2/action';
    $title2 = 'test2';
    $_SERVER['REQUEST_URI'] = $uri2;
    $this->Controller->pageTitle = $title2;
    array_unshift($expected[Configure::read('Runtime.site_or_admin')], array('uri' => $uri2, 'title' => $title2));
    $this->Controller->History->beforeRender();
    $this->assertEqual($this->Controller->Session->read($this->historyKey), $expected);

    /**
     * test new uri does not get pushed onto front of stack if bare
     */
    $uri3 = '/controller3/action';
    $title3 = 'test3';
    $_SERVER['REQUEST_URI'] = $uri3;
    $this->Controller->pageTitle = $title3;
    $this->Controller->params['bare'] = 1;
    $this->Controller->History->beforeRender();
    $this->assertEqual($this->Controller->Session->read($this->historyKey), $expected);

    /**
     * test new uri does get pushed onto front of stack if not bare
     */
    unset($this->Controller->params['bare']);
    $this->Controller->History->beforeRender();
    array_unshift($expected[Configure::read('Runtime.site_or_admin')], array('uri' => $uri3, 'title' => $title3));
    $this->assertEqual($this->Controller->Session->read($this->historyKey), $expected);

    /**
     * test previous uri pops current uri off front of stack
     */
    $_SERVER['REQUEST_URI'] = $uri2;
    $this->Controller->pageTitle = $title2;
    $this->Controller->History->beforeRender();
    array_shift($expected[Configure::read('Runtime.site_or_admin')]);
    $this->assertEqual($this->Controller->Session->read($this->historyKey), $expected);

    /**
     * test new uri in admin routing
     */
    Configure::write('Runtime.site_or_admin', 'admin');
    $uri4 = '/'.Configure::read('Routing.admin').'/controller4/action';
    $_SERVER['REQUEST_URI'] = $uri4;
    $this->Controller->pageTitle = null;
    $expected[Configure::read('Runtime.site_or_admin')][] = array('uri' => $uri4, 'title' => __($this->defaultTitle, true));
    $this->Controller->History->beforeRender();
    $this->assertEqual($this->Controller->Session->read($this->historyKey), $expected);

  }

  function testBack() {
    $this->Controller->History->startup($this->Controller);
    $this->Controller->Session->del($this->historyKey);
    Configure::write('Runtime.site_or_admin', 'site');

    $history = array(
      'site' => array(
        array(
          'uri' => '/controller1/action',
          'title' => 'title1',
        ),
        array(
          'uri' => '/controller2/action',
          'title' => 'title2',
        ),
        array(
          'uri' => '/controller3/action',
          'title' => 'title3',
        ),
      ),
      'admin' => array(
        array(
          'uri' => '/'.Configure::read('Routing.admin').'/controller1/action',
          'title' => 'title1',
        ),
        array(
          'uri' => '/'.Configure::read('Routing.admin').'/controller2/action',
          'title' => 'title2',
        ),
        array(
          'uri' => '/'.Configure::read('Routing.admin').'/controller3/action',
          'title' => 'title3',
        ),
      ),
    );

    $this->Controller->Session->write($this->historyKey, $history);

    /**
     * test back() with default index
     */
    $expected = Router::normalize($history[Configure::read('Runtime.site_or_admin')][1]['uri']);
    $this->Controller->History->back();
    $this->assertEqual($this->Controller->testUrl, $expected);

    /**
     * test back() with specific index
     */
    $expected = Router::normalize($history[Configure::read('Runtime.site_or_admin')][0]['uri']);
    $this->Controller->History->back(0);
    $this->assertEqual($this->Controller->testUrl, $expected);

    /**
     * test back() with non-existent index and default
     */
    $default = '/default_controller/default_action';
    $expected = Router::normalize($default);
    $this->Controller->History->back(count($history[Configure::read('Runtime.site_or_admin')]), $default);
    $this->assertEqual($this->Controller->testUrl, $expected);

    /**
     * test back() with non-existent index and no default
     */
    $expected = Router::normalize(array('action' => 'index'));
    $this->Controller->History->back(count($history[Configure::read('Runtime.site_or_admin')]));
    $this->assertEqual($this->Controller->testUrl, $expected);

    /**
     * test back() with non-existent index and no default and in admin
     */
    Configure::write('Runtime.site_or_admin', 'admin');
    $expected = Router::normalize(array('action' => 'index', 'admin' => true));
    $this->Controller->History->back(count($history[Configure::read('Runtime.site_or_admin')]));
    $this->assertEqual($this->Controller->testUrl, $expected);

  }

  function tearDown() {
    ClassRegistry::flush();
    unset($this->Controller->History, $this->Controller);
  }

}
?>