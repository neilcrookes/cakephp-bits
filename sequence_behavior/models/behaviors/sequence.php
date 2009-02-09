<?php
/**
 * SequenceBehavior
 *
 * Summary:
 *  Maintains a contiguous sequence 0-indexed (or configurable start int), when
 *  adding or editing records. Sequences can apply to all records in the whole
 *  table, or a set of records identified by a single or multiple "group" keys.
 *
 * Description:
 *  Consider the following example:
 *  Record  Order
 *  A       0
 *  B       1
 *  C       2
 *  D       3
 *  E       4
 *  F       5
 *  G       6
 *  - Save
 *    - If adding new record
 *      - If order not specified e.g. Record H added:
 *          Inserts H at end of list i.e. highest order + 1
 *      - If order specified e.g. Record H added at position 3:
 *          Inserts at specified order
 *          Increments order of all other records whose order >= order of
 *           inserted record i.e. D, E, F & G get incremented
 *    - If editing existing record:
 *      - If order not specified and group not specified, or same
 *          No Action
 *      - If order not specified but group specified and different:
 *          Decrement order of all records whose order > old order in the old
 *           group, and change order to highest order of new groups + 1
 *      - If order specified:
 *        - If new order < old order e.g. record E moves from 4 to 2
 *            Increments order of all other records whose order >= new order and
 *             order < old order i.e. order of C & D get incremented
 *        - If new order > old order e.g. record C moves from 2 to 4
 *            Decrements order of all other records whose order > old order and
 *             <= new order i.e. order of D & E get decremented
 *        - If new order == old order
 *            No action
 *  - Delete
 *      Decrement order of all records whose order > order of deleted record
 *
 * @author Neil Crookes <neil@neilcrookes.com>
 * @link http://www.neilcrookes.com
 * @copyright (c) 2008 Neil Crookes
 * @license MIT License - http://www.opensource.org/licenses/mit-license.php
 * @link http://github.com/neilcrookes/cakephp/tree/master
 *
 */

class SequenceBehavior extends ModelBehavior {

  /**
   * Default settings for a model that has this behavior attached.
   *
   * @var array
   * @access protected
   */
  protected $_defaults = array(
    'order_field' => 'order',
    'group_fields' => false,
    'start_at' => 0,
  );

  /**
   * The order field name of the current model, e.g. order or sequence_number etc
   *
   * @var string
   */
  var $orderField;

  /**
   * Array of strings of field names that this model's records are grouped by.
   * For example a single Comment model could be used for storing comments for
   * both blog posts and news articles, therefore Comment has fields for model,
   * in which values could be BlogPost or NewsArticle and modelid, which
   * corresponds to the individual BlogPost or NewsArticle.
   *
   * @var array
   */
  var $groupFields;

  /**
   * Stores the current order of the record being edited, or null if adding
   *
   * @var integer
   */
  var $oldOrder = null;

  /**
   * Stores the new order of the record being edited or added
   *
   * @var integer
   */
  var $newOrder = null;

  /**
   * Stores the current values of the group fields for the record being edited,
   * before it's saved, i.e. they are fetched from the database. In the form
   * array(
   *   group_field => group_field_value,
   *   ...
   * )
   *
   * @var array
   */
  var $oldGroups = null;

  /**
   * Stores the new values of the group fields for the record being edited,
   * before it's saved, i.e. they are what's in model->data. In the form
   * array(
   *   group_field => group_field_value,
   *   ...
   * )
   *
   * @var array
   */
  var $newGroups = null;

  /**
   * Stores the update instructions with keys for update, which is the actual
   * <field> => <field> +/- 1 part, and for conditions, which identify the
   * records to which the update will be applied.
   *
   * @var array
   */
  var $update = null;

  /**
   * Merges the passed config array defined in the model's actsAs property with
   * the behavior's defaults and stores the resultant array in this->settings.
   * Configuration options include:
   *  - order_field  - The database field name that stores the sequence number.
   *                   Default is order.
   *  - group_fields - Array of database field names that identify a single
   *                   group of records that need to form a contiguous sequence
   *                   Default is false, i.e. no group fields
   *  - start_at     - You can start your sequence numbers at 0 or 1 or any other
   *                   Default is 0
   *
   * @param AppModel $model
   * @param array $config
   */
  function setup(&$model, $config = array()) {

    // Ensure config is an array
    if (!is_array($config)) {
      $config = array($config);
    }

    // Merge defaults with passed config and set as settings
    $this->settings[$model->alias] = array_merge($this->_defaults, $config);

    // Set the order field property with this model's order_field setting
    $this->orderField = $this->settings[$model->alias]['order_field'];

    // If group_fields in settings is a string, make it an array
    if (is_string($this->settings[$model->alias]['group_fields'])) {
      $this->settings[$model->alias]['group_fields'] = array($this->settings[$model->alias]['group_fields']);
    }

    // Set group fields property with this model's group_field setting
    $this->groupFields = $this->settings[$model->alias]['group_fields'];

    // Set the model's order property and the escapedOrderField property to
    // a string in the form `Model`.`order_field`
    $model->order = $this->escapedOrderField = $model->escapeField($this->orderField);

    // Set the escaped group fields property to an array of
    // group_field => `Model`.`group_field` pairs
    if ($this->groupFields) {
      foreach ($this->groupFields as $groupField) {
        $this->escapedGroupFields[$groupField] = $model->escapeField($groupField);
      }
    }

  }

  /**
   * Called automatically before model gets saved
   *
   * @param AppModel $model
   * @return boolean Always true otherwise model will not save
   */
  function beforeSave(&$model) {

    $this->setSaveUpdateData($model);

    return true;

  }

  /**
   * Called automatically after model gets saved
   *
   * @param AppModel $model
   * @param boolean $created
   * @return boolean
   */
  function afterSave(&$model, $created) {

    return $this->updateAll($model);

  }

  /**
   * Called automatically before model gets deleted
   *
   * @param AppModel $model
   * @return boolean Always true
   */
  function beforeDelete(&$model) {

    $this->setDeleteUpdateData($model);

    return true;

  }

  /**
   * Called automatically after model gets deleted
   *
   * @param AppModel $model
   * @return boolean
   */
  function afterDelete(&$model) {

    return $this->updateAll($model);

  }

  /**
   * Sets update actions and their conditions which get executed in after save
   *
   * @param AppModel $model
   */
  function setSaveUpdateData(&$model) {

    // Sets new order and new groups from model->data
    $this->setNewOrder($model);
    $this->setNewGroups($model);

    // Adding
    if (!$model->id) {

      // Order not specified
      if (is_null($this->newOrder)) {

        // Insert at end of list
        $model->data[$model->alias][$this->orderField] = $this->getHighestOrder($model, $this->newGroups) + 1;

      // Order specified
      } else {

        // The updateAll called in afterSave uses old groups values as default
        // conditions to restrict which records are updated, so set old groups
        // to new groups as old isn't set.
        $this->oldGroups = $this->newGroups;

        // Insert and increment order of records it's inserted before
        $this->update = array(
          'action' => array(
            $this->escapedOrderField => $this->escapedOrderField . ' + 1'
          ),
          'conditions' => array(
            $this->escapedOrderField . ' >=' => $this->newOrder
          ),
        );

      }

    // Editing
    } else {

      // No action if no new order or group specified
      if (is_null($this->newOrder) && is_null($this->newGroups)) {
        return;
      }

      $this->setOldOrder($model);
      $this->setOldGroups($model);

      // No action if new and old group and order same
      if ($this->newOrder == $this->oldOrder
      && Set::isEqual($this->newGroups, $this->oldGroups)) {
        return;
      }

      // If changing group
      if ($this->newGroups && !Set::isEqual($this->newGroups, $this->oldGroups)) {

        // Decrement records in old group with higher order than moved record old order
        $this->update = array(
          'action' => array(
            $this->escapedOrderField => $this->escapedOrderField . ' - 1'
          ),
          'conditions' => array(
            $this->escapedOrderField . ' >=' => $this->oldOrder,
          ),
        );

        // Insert at end of new group
        $model->data[$model->alias][$this->orderField] = $this->getHighestOrder($model, $this->newGroups) + 1;

      // Same group
      } else {

        // If moving up
        if ($this->newOrder < $this->oldOrder) {

          // Increment order of those in between
          $this->update = array(
            'action' => array(
              $this->escapedOrderField => $this->escapedOrderField . ' + 1'
            ),
            'conditions' => array(
              array($this->escapedOrderField . ' >=' => $this->newOrder),
              array($this->escapedOrderField . ' <' => $this->oldOrder),
            ),
          );

        // Moving down
        } else {

          // Decrement order of those in between
          $this->update = array(
            'action' => array(
              $this->escapedOrderField => $this->escapedOrderField . ' - 1'
            ),
            'conditions' => array(
              array($this->escapedOrderField . ' >' => $this->oldOrder),
              array($this->escapedOrderField . ' <=' => $this->newOrder),
            ),
          );

        }

      }

    }

  }

  /**
   * Returns the current highest order of all records in the set. When a new
   * record is added to the set, it is added at the current highest order, plus
   * one.
   *
   * @param AppModel $model
   * @param array $groupValues Array with group field => group values, used for conditions
   * @return integer Value of order field of last record in set
   */
  function getHighestOrder(&$model, $groupValues = false) {

    // Conditions for the record set to which this record will be added to.
    $conditions = $this->conditionsForGroups($model, $groupValues);

    // Find the last record in the set
    $last = $model->find('first', array(
      'conditions' => $conditions,
      'recursive' => -1,
      'order' => $this->escapedOrderField . ' DESC',
    ));

    // If there is a last record (i.e. any) in the set, return the it's order
    if ($last) {
      return $last[$model->alias][$this->orderField];
    }

    // If there isn't any records in the set, return the start number minus 1
    return ((int)$this->settings[$model->alias]['start_at'] - 1);

  }

  /**
   * If editing a record, set the oldOrder property to the current order in the
   * database.
   *
   * @param AppModel $model
   */
  function setOldOrder(&$model) {

    // If no id, we're creating not editing, so there is no old order, and it remains null
    if (!$model->id) {
      return;
    }

    // Set old order to record's current order in database
    $this->oldOrder = $model->field($model->alias.'.'.$this->orderField);

  }

  /**
   * If editing a record, set the oldGroups property to the current group values
   * in the database for each group field for this model.
   *
   * @param AppModel $model
   */
  function setOldGroups(&$model) {

    // If no id, we're creating not editing, so there is no old groups
    if (!$model->id) {
      return;
    }

    // If this model does not have any groups, return
    if (!$this->groupFields) {
      return;
    }

    // Set oldGroups property with key of group field and current value of group
    // field from db
    foreach ($this->groupFields as $groupField) {

      $this->oldGroups[$groupField] = $model->field($model->alias.'.'.$groupField);

    }

  }

  /**
   * Sets new order property to value in model->data
   *
   * @param AppModel $model
   */
  function setNewOrder(&$model) {

    if (!isset($model->data[$model->alias][$this->orderField])) {
      return;
    }

    $this->newOrder = $model->data[$model->alias][$this->orderField];

  }

  /**
   * Set new groups property with keys of group field and values from
   * $model->data, if set.
   *
   * @param AppModel $model
   */
  function setNewGroups(&$model) {

    // Return if this model has not group fields
    if (!$this->groupFields) {
      return;
    }

    foreach ($this->groupFields as $groupField) {

      if (isset($model->data[$model->alias][$groupField])) {

        $this->newGroups[$groupField] = $model->data[$model->alias][$groupField];

      }

    }

  }

  /**
   * Returns array of conditions for restricting a record set according to the
   * model's group fields setting.
   *
   * @param AppModel $model
   * @param array $groupValues Array of group field => group value pairs
   * @return array Array of escaped group field => group value pairs
   */
  function conditionsForGroups(&$model, $groupValues = false) {

    if (!$this->groupFields) {
      return array();
    }

    $conditions = array();

    // By default, if group values are not specified, use the old group fields
    if ($groupValues === false) {

      $groupValues = $this->oldGroups;

    }

    // Set up conditions for each group field
    foreach ($this->groupFields as $groupField) {

      $conditions[] = array(
        $this->escapedGroupFields[$groupField] => $groupValues[$groupField],
      );

    }

    return $conditions;

  }

  /**
   * When doing any update all calls, you want to avoid updating the record
   * you've just modified, as the order will have been set already, so exclude
   * it with some conditions.
   *
   * @param AppModel $model
   * @return array Array Model.primary_key => $id
   */
  function conditionsNotCurrent(&$model) {

    if (!$id = $model->id) {
      $id = $model->getInsertID();
    }

    return array($model->escapeField($model->primaryKey) . ' <>' => $id);

  }

  /**
   * When you delete a record from a set, you need to decrement the order of all
   * records that were after it in the set.
   *
   * @param AppModel $model
   */
  function setDeleteUpdateData(&$model) {

    // Set current order and groups of record to be deleted
    $this->setOldOrder($model);
    $this->setOldGroups($model);

    // Decrement records in group with higher order than deleted record
    $this->update = array(
      'action' => array(
        $this->escapedOrderField => $this->escapedOrderField . ' - 1'
      ),
      'conditions' => array(
        $this->escapedOrderField . ' >' => $this->oldOrder,
      ),
    );

  }

  /**
   * Executes the update, if there are any. Called in afterSave and afterDelete.
   *
   * @param AppModel $model
   * @return boolean
   */
  function updateAll(&$model) {

    // If there's no update to do
    if (!$this->update) {
      return true;
    }

    // Actual conditions for the update are a combination of what's derived in
    // the setSaveUpdateData or setDeleteUpdateData, and conditions to not update
    // the record we've just modified/inserted and conditions to make sure only
    // records in the current record's groups
    $conditions = array_merge(
      $this->conditionsForGroups($model),
      $this->conditionsNotCurrent($model),
      $this->update['conditions']
    );

    return $model->updateAll($this->update['action'], $conditions);

  }

}
?>