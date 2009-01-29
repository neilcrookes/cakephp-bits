<?php
Router::connect('/search/:term/*', array('controller' => 'searches', 'action' => 'results'));
?>
