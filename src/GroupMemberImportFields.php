<?php

namespace Drupal\group_member_import;

use Drupal\node\Entity\Node;
use Drupal\file\Entity\File;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;


/**
 * Implements form to upload a file and start the batch on form submit.
 */
class GroupMemberImportFields {

  /**
   * getFieldType
   *
   * @param string $bundle
   *   The bundle for the entity
   * @param string $field
   *   The field name to get information for
   * @return array $options_array
   *   Returns a array of field definitions
   */
    public function getFieldType($bundle, $field) {

        if ($bundle === 'user') {
            $entity_type = 'user';
        }
        else {
            $entity_type = 'profile';
        }

        $options_array = [];
        $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $bundle);
    
        if (isset($definitions[$field])) {
          $options_array = $definitions[$field]->getType();
        }
        
        return $options_array;                

    }

    /**
     * getTermReference
     *
     * @param string $voc
     *   The vocabulary for the taxonomy
     * @param string $terms
     *   The terms for the taxonomy
     * @return array $terms_array
     *   Returns a array of term ids
     */
    public function getTermReference($voc, $terms) {

        $vocName = strtolower($voc);
        $vid = preg_replace('@[^a-z0-9_]+@', '_', $vocName);
        $vocabularies = Vocabulary::loadMultiple();
            /* Create Vocabulary if it is not exists */
            if (!isset($vocabularies[$vid])) {
                create_voc($vid, $voc);
            }
        $termArray = array_map('trim', explode(',', $terms));
        $termIds = [];
        foreach ($termArray as $term) {
            $term_id = $this->get_term_id($term, $vid);
            if (empty($term_id)) {
                $term_id = create_term($voc, $term, $vid);
            }
            $termIds[]['target_id'] = $term_id;
        }
  
        return $termIds;

    }

    /**
     * To Create Terms if it is not available.
     */
    protected function create_voc($vid, $voc) {
        
        $vocabulary = Vocabulary::create(
          [
            'vid' => $vid,
            'machine_name' => $vid,
            'name' => $voc,
          ]
        );

        $vocabulary->save();

    }
  
    /**
     * To Create Terms if it is not available.
     */
    protected function create_term($voc, $term, $vid) {
        Term::create(
          [
            'parent' => [$voc],
            'name' => $term,
            'vid' => $vid,
          ]
        )->save();
    
        $termId = $this->get_term_id($term, $vid);
        return $termId;
        
    }
  
    /**
     * To get Termid available.
     */
    protected function get_term_id($term, $vid) {
        $query = \Drupal::database()->select('taxonomy_term_field_data', 't');
        $query->fields('t', ['tid']);
        $query->condition('t.vid', $vid);
        $query->condition('t.name', $term);
        $termRes = $query->execute()->fetchAll();
        
        foreach ($termRes as $val) {
            $term_id = $val->tid;
        }

        return $term_id;

    }
  
    /**
     * To get node available.
     */
    public function get_node_id($title) {
        $nodeReference = [];
        $db = \Drupal::database();
        foreach ($title as $key => $value) {
            $query = $db->select('node_field_data', 'n');
            $query->fields('n', ['nid']);
            $nodeId = $query
                ->condition('n.title', trim($value))
                ->execute()
                ->fetchField();
            $nodeReference[$key]['target_id'] = $nodeId;
        }
    
        return $nodeReference;

    }
  
    /**
     * To get user id.
     */
    protected function get_user_id($name) {
        $user_id = \Drupal::database()
            ->select('users_field_data', 'u')
            ->fields('u', ['uid'])
            ->condition('u.name', trim($name))
            ->execute()
            ->fetchField();

        return $user_id;

    }

    /**
     * To get field settings
     */
    public function getFieldSettings($bundle, $field) {

        if ($bundle === 'user') {
            $entity_type = 'user';
        }
        else {
            $entity_type = 'profile';
        }


        $options_array = [];
        $definitions = \Drupal::service('entity_field.manager')->getFieldDefinitions($entity_type, $bundle);
    
        if (isset($definitions[$field])) {
          $options_array = $definitions[$field]->getSettings();
        }
        
        return $options_array;
                

    }

    public function importUser($userArray, $profileArray, $group) {

        $usermail = FALSE;
        
        //We do not want some fields in our user array        
        if (array_key_exists('mail', $userArray)) {
            $usermail = $userArray['mail'];
            unset($userArray['mail']);
        }

        //We do not want some fields in our user array         
        if (array_key_exists('init', $userArray)) {
            $usermail = $userArray['init'];
            unset($userArray['init']);
        }
        

        // We need the usermail to proceed
        if ($usermail) {

            // Check if we need to update an existing record
            $account = user_load_by_mail($usermail);
            if (!$account) {
                // Insert new user                   
                $user = User::create($userArray);
                $user->uid = '';
                $username = $this->getUserNameFromEmail($usermail);
                $user->setUsername($username);
                $user->setEmail($usermail);
                $user->set("init", $usermail);
                $user->enforceIsNew();
                $user->activate();
                $user->save();
                $uid = $user->id();

                $current_user = user_load($uid); 

                foreach ($profileArray as $profile_id => $profile_fields) {   

                    $active_profile = \Drupal::entityTypeManager()
                    ->getStorage('profile')
                    ->loadByUser($current_user, $profile_id);

                    $profileFieldArray = $profile_fields;

                    if (is_array($profileFieldArray) && !empty($profileFieldArray)) {

                        if ($active_profile && $active_profile->bundle() === $profile_id) {
                           
                            foreach ($profileFieldArray as $field_name => $field_value) {

                                $active_profile->set($field_name,$field_value);
                                $active_profile->save();
    
                            }
                        }
                    } 
                }

                // Attach the imported member to his group
                if($group) {
                    $group_storage = \Drupal::entityTypeManager()->getStorage('group')->load($group);
                    $group_storage->addMember($current_user);
                }

            }
            else {
                // Update User
                $user = $account;
                foreach($userArray as $field_name => $field_value) {
                    // Avoid updating password
                    if ($field_name != 'pass')  {
                        $user->set($field_name,$field_value);
                    }
                }

                foreach ($profileArray as $profile_id => $profile_fields) {   

                    $active_profile = \Drupal::entityTypeManager()
                    ->getStorage('profile')
                    ->loadByUser($user, $profile_id);

                    $profileFieldArray = $profile_fields;

                    if (is_array($profileFieldArray) && !empty($profileFieldArray)) {

                        if ($active_profile && $active_profile->bundle() === $profile_id) {
                           
                            foreach ($profileFieldArray as $field_name => $field_value) {

                                $active_profile->set($field_name,$field_value);
                                $active_profile->save();
    
                            }  


                        }
                    } 
                }

                if($group) {
                    $group_storage = \Drupal::entityTypeManager()->getStorage('group')->load($group);
                    $group_storage->addMember($user);
                }
          
                $user->save();

            }





        }




    }

    protected function getUserNameFromEmail(string $email) {
        $array = explode("@", $email);
        $username = $array[0];
    
        if (user_load_by_name($username)) {
          $random_number = random_int(2,6);
          $username = $username . '_' . $random_number;
        }
    
        return $username;
    }


    public function getAvailableAccountFields() {

       
        $fields = [];
        foreach (\Drupal::service('entity_field.manager')
          ->getFieldDefinitions('user', 'user') as $field_definition) {
          if (!empty($field_definition->getTargetBundle())) {
            $fields['name'][] = $field_definition->getName();
            $fields['type'][] = $field_definition->getType();
            $fields['setting'][] = $field_definition->getSettings();
          }
        }
        return $fields;        

    }

    public function getAvailableAccountBaseFields2() {

        $fields = [];
        foreach (\Drupal::service('entity_field.manager')
          ->getBaseFieldDefinitions('user', 'user') as $field_definition) {
          
            $fields['name'][] = $field_definition->getName();
            $fields['type'][] = $field_definition->getType();
            $fields['setting'][] = $field_definition->getSettings();
         
        }
        return $fields;  
        

    }

    protected function getColumnDefinitions($field_definition_names, $bundle) {

        $columns = [];
        $definition[] = $field_definition_names; 
        $allowedFields = $this->getAllowedAccountBaseFields();

        if (isset($definition) && !empty($definition)) {
            foreach ($definition as $field) {


                if(strpos($field, 'field') !== false) {

                    $columns = $bundle . '.' . $field;

                }

            }
        }

        return $columns;


    }

    public function getAllAvailableFields() {

        $columns = [];
        $fields = [];
        $allowedFields = $this->getAllowedAccountBaseFields();
       

        // Get attached fields
        foreach (\Drupal::service('entity_field.manager')
          ->getFieldDefinitions('user', 'user') as $field_definition) {

            $definition[] = $field_definition->getName();

            if (isset($definition) && !empty($definition)) {
                foreach ($definition as $field) {   
                    if (in_array($field,$allowedFields)) {
                        $columns = 'user.' . $field;
                    }
                }
            }

            $fields[] = $columns;

        }

        // Get Profile fields
        $profile_types = \Drupal::service('entity_type.bundle.info')->getBundleInfo('profile');

        foreach ($profile_types as $bundle => $profile) {

            foreach (\Drupal::service('entity_field.manager')
              ->getFieldDefinitions('profile', $bundle) as $field_definition) {

                $fields[] = $this->getColumnDefinitions($field_definition->getName(),$bundle);

            }
        }


        return $fields;

    }

    public function getAllAvailableFieldsPerEntity() {

        $columns = [];
        $fields = [];
        $allowedFields = $this->getAllowedAccountBaseFields();
       

        // Get attached fields
        foreach (\Drupal::service('entity_field.manager')
          ->getFieldDefinitions('user', 'user') as $field_definition) {

            $definition[] = $field_definition->getName();

            if (isset($definition) && !empty($definition)) {
                foreach ($definition as $field) {     
                    if (in_array($field,$allowedFields) || strpos($field, 'field_')) {
                        $columns = 'user.' . $field;
                    }
                }
            }

            $fields['user'][] = $columns;

        }


        // Get Profile fields
        $profile_types = \Drupal::service('entity_type.bundle.info')->getBundleInfo('profile');

        foreach ($profile_types as $bundle => $profile) {

            foreach (\Drupal::service('entity_field.manager')
              ->getFieldDefinitions('profile', $bundle) as $field_definition) {

                $fields[$bundle][] = $this->getColumnDefinitions($field_definition->getName(),$bundle);

            }
        }



        return $fields;

    }
    
    protected function getAllowedAccountBaseFields() {

        return [
          'name',
          'pass',
          'mail',
          'timezone',
          'status',
          'langcode'
        ];

    }

    






}