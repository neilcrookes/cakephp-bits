<?php

class SearchesController extends AppController {

  var $paginate = array(
    'limit' => 10,
    'page' => 1
  );

  function results() {

    if (!empty($this->data['Search']['term'])) {
      $this->redirect(array('term' => $this->data['Search']['term']));
    }

    $term = '';
    if (isset($this->params['term'])) {
      $term = $this->params['term'];
    }

    if (isset($this->passedArgs['show'])) {
      $this->paginate['limit'] = $this->passedArgs['show'];
    }

    if (isset($this->passedArgs['page'])) {
      $this->paginate['page'] = $this->passedArgs['page'];
    }

    $this->Search->paginate = $this->paginate;

    $results = $this->paginate('Search', array('term' => $term));

    $spellingSuggestion = $this->Search->spellingSuggestion($term);

    $this->set(compact('results', 'term', 'spellingSuggestion'));

  }

}

?>