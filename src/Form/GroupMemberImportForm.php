<?php

namespace Drupal\group_member_import\Form;

use Drupal\Component\Utility\Environment;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;
use Drupal\user\Entity\User;
use Drupal\group_member_import\GroupMemberImportFields;
use Drupal\Component\Utility\Unicode;


/**
 * Implements form to upload a file and start the batch on form submit.
 *
 * @see \Drupal\Core\Form\FormBase
 * @see \Drupal\Core\Form\ConfigFormBase
 */
class GroupMemberImportForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'csvimport_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, GroupInterface $group = null) {

    if (!is_null($group) && is_object($group)) {
      $current_user = \Drupal::currentUser();
      $options_group_roles = [];
      $group_type = $group->getGroupType();
      $group_roles = \Drupal::entityTypeManager()->getStorage('group_role')->loadMultiple();

      //kint($group_roles);

      if (!empty($group_type) && !empty($group_roles)) {       
        /** @var  \Drupal\group\Entity\GroupRole $group_role */
        foreach ($group_roles as $role_id => $group_role) {
          if ($group_role->getGroupTypeId() == $group_type->id()) {
            if(!$group_role->isInternal()) {
              $options_group_roles[$role_id] = $group_role->label();           
            }
          }
        }
      }
    }    

    $options_user_roles = \Drupal::state()->get('group_member_import_allowed_roles');

    

   
    $form['#attributes'] = [
      'enctype' => 'multipart/form-data',
    ];

    $form['csvfile'] = [
      '#title'            => $this->t('CSV File') . ' * ',
      '#type'             => 'file',
      '#description'      => ($max_size = Environment::getUploadMaxSize()) ? $this->t('Due to server restrictions, the <strong>maximum upload file size is @max_size</strong>. Files that exceed this size will be disregarded.', ['@max_size' => format_size($max_size)]) : '',
      '#element_validate' => ['::validateFileupload'],
    ];

    $options = array(
      1 => ';',
      2 => ','
    );

    $form['delimiter'] = array(
      '#type' => 'select',
      '#options' => $options,
      '#title' => $this->t('Delimiter')
    );

    $form['group_roles'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Assign group roles'),
      '#options' => $options_group_roles,
    );

    $form['user_roles'] = array(
      '#type' => 'checkboxes',
      '#title' => $this->t('Assign user roles'),
      '#options' => $options_user_roles,      
    );



    $form['group'] = array(
      '#type' => 'entity_autocomplete',
      '#target_type' => 'group',
      '#default_value' => $group, // The #default_value can be either an entity object or an array of entity objects.
      '#disabled' => TRUE
    );

    $form['submit'] = [
      '#type'  => 'submit',
      '#value' => $this->t('Start Import'),
    ];

    return $form;

  }

  /**
   * Validate the file upload.
   */
  public static function validateFileupload(&$element, FormStateInterface $form_state, &$complete_form) {

    $validators = [
      'file_validate_extensions' => ['csv'],
    ];



    // @TODO: File_save_upload will probably be deprecated soon as well.
    // @see https://www.drupal.org/node/2244513.
    if ($file = file_save_upload('csvfile', $validators, FALSE, 0, FILE_EXISTS_REPLACE)) {

      // The file was saved using file_save_upload() and was added to the
      // files table as a temporary file. We'll make a copy and let the
      // garbage collector delete the original upload.
      $csv_dir = 'temporary://csvfile';
      $directory_exists = \Drupal::service('file_system')
        ->prepareDirectory($csv_dir, FileSystemInterface::CREATE_DIRECTORY);

      if ($directory_exists) {
        $destination = $csv_dir . '/' . $file->getFilename();
        if (file_copy($file, $destination, FileSystemInterface::EXISTS_REPLACE)) {
          $form_state->setValue('csvupload', $destination);
        }
        else {
          $form_state->setErrorByName('csvimport', t('Unable to copy upload file to @dest', ['@dest' => $destination]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {

    $delimiter = $form_state->getValue('delimiter');
    $csvupload = $form_state->getValue('csvupload');

    if (!$csvupload) {

      $form_state->setErrorByName('csvfile', $this->t('You have to upload a file!'));
    
    }

    
    if ($delimiter == 1) {
      $delimiter = ';';
    }
    else {
      $delimiter = ',';
    }


 

    if ($csvupload = $form_state->getValue('csvupload')) {

      ini_set('auto_detect_line_endings', true);

      if ($handle = fopen($csvupload, 'r')) {

        if ($line = fgetcsv($handle, 4096, $delimiter)) {

          $group_member_import_fields = new GroupMemberImportFields();
          $availableFields = $group_member_import_fields->getAllAvailableFields();

          foreach($line as $line_field) {

            if (!array_key_exists($line_field, $availableFields) == FALSE) {
              
              $form_state->setErrorByName('csvfile', $this->t('Field data do not match'));

            }
          }


        }
        fclose($handle);
      }
      else {
        $form_state->setErrorByName('csvfile', $this->t('Unable to read uploaded file @filepath', ['@filepath' => $csvupload]));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {

    $group_member_import_fields = new GroupMemberImportFields();
    $availableFields = $group_member_import_fields->getAllAvailableFields();
    $active_group_roles = [];
    $active_user_roles = [];
    $newLine = [];
    $header = null;
    $i = 0;

    $group = $form_state->getValue('group');
    
    
    $group_roles = $form_state->getValue('group_roles');
    foreach($group_roles as $key => $role) {
      if ($key === $role) {
        $active_group_roles[$key] = $key;
      }
    }

    $user_roles = $form_state->getValue('user_roles');
    foreach($user_roles as $key => $role) {
      if ($key === $role) {
        $active_user_roles[$key] = $key;
      }
    }


    $delimiter = $form_state->getValue('delimiter');

    if ($delimiter == 1) {
      $delimiter = ';';
    }
    else {
      $delimiter = ',';
    }


    $batch = [
      'title'            => $this->t('Importing CSV ...'),
      'operations'       => [],
      'init_message'     => $this->t('Commencing'),
      'progress_message' => $this->t('Processed @current out of @total.'),
      'error_message'    => $this->t('An error occurred during processing'),
      'finished'         => '\Drupal\group_member_import\Batch\GroupMemberImportBatch::csvimportImportFinished'
    ];

    if ($csvupload = $form_state->getValue('csvupload')) {

      if ($handle = fopen($csvupload, 'r')) {

        $batch['operations'][] = [
          '\Drupal\group_member_import\Batch\GroupMemberImportBatch::csvimportRememberFilename',
          [$csvupload]
        ];

        while ($line = fgetcsv($handle, 4096, $delimiter)) { 
          
          foreach ($availableFields as $fields) {
            
            foreach ($line as $lineFields) {

              if ($fields == $lineFields) {

                $line_headers = $line;

              }

            }

          }
          
          
          // Use base64_encode to ensure we don't overload the batch
          // processor by stuffing complex objects into it.
          $batch['operations'][] = [
            '\Drupal\group_member_import\Batch\GroupMemberImportBatch::csvimportImportLine',
            [array_map('base64_encode', $line),$group, $active_group_roles, $active_user_roles, $line_headers]
          ];

        }

        fclose($handle);
      }
    }

    batch_set($batch);
  }


    /**
   * Checks access for a specific request.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   Run access checks for this account.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, GroupInterface $group = NULL) {
    // Check permissions and combine that with any custom access checking needed. Pass forward
    // parameters from the route and/or request as needed.

    $user = User::load($account->id());

    //kint($group);

    if ($group) {

      $member = $group->getMember($account);

      if ($member) {
        if($member->hasPermission('edit group', $account)) {
          return AccessResult::allowed();
        }
      }
      elseif ($user->hasRole('administrator')) {
        return AccessResult::allowed();
      }

    }

    return AccessResult::forbidden();

  }


}
