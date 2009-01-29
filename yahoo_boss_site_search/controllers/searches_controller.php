<?php

class SearchesController extends AppController {

  function results() {

    if (!empty($this->data['Search']['term'])) {
      $this->redirect(array('term' => $this->data['Search']['term']));
    }

    $term = '';
    if (isset($this->params['term'])) {
      $term = $this->params['term'];
    }

    if (isset($this->passedArgs['show'])) {
      $this->Search->paginate['limit'] = $this->passedArgs['show'];
    }

    if (isset($this->passedArgs['page'])) {
      $this->Search->paginate['page'] = $this->passedArgs['page'];
    }

    $results = $this->paginate('Search', array('term' => $term));

    $spellingSuggestion = $this->Search->spellingSuggestion($term);

    $this->set(compact('results', 'term', 'spellingSuggestion'));

  }

}

?>