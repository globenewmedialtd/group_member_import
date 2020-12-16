<?php

namespace Drupal\group_member_import\Batch;

use Drupal\Core\File\FileSystemInterface;
use Drupal\user\Entity\User;
use Drupal\user\Entity\UserInterface;
use Drupal\profile\Entity\Profile;
use Drupal\group\Entity\Group;
use Drupal\group\Entity\GroupInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\group_member_import\GroupMemberImportFields;


// @codingStandardsIgnoreEnd

/**
 * Methods for running the CSV import in a batch.
 *
 * @package Drupal\csvimport
 */
class GroupMemberImportBatch {


  /**
   * Handle batch completion.
   *
   *   Creates a new CSV file containing all failed rows if any.
   */
  public static function csvimportImportFinished($success, $results, $operations) {

    $messenger = \Drupal::messenger();

    \Drupal::logger('social_welcome_message')->notice('<pre><code>' . print_r($results, TRUE) . '</code></pre>');

    if (!empty($results['failed_rows'])) {

      $dir = 'public://csvimport';
      if (\Drupal::service('file_system')
        ->prepareDirectory($dir, FileSystemInterface::CREATE_DIRECTORY)) {

        // We validated extension on upload.
        $csv_filename = 'failed_rows-' . basename($results['uploaded_filename']);
        $csv_filepath = $dir . '/' . $csv_filename;

        $targs = [
          ':csv_url'      => file_create_url($csv_filepath),
          '@csv_filename' => $csv_filename,
          '@csv_filepath' => $csv_filepath,
        ];

        ini_set('auto_detect_line_endings', true);

        if ($handle = fopen($csv_filepath, 'w+')) {

          foreach ($results['failed_rows'] as $failed_row) {
            fputcsv($handle, $failed_row);
          }

          fclose($handle);
          $messenger->addMessage(t('Some rows failed to import. You may download a CSV of these rows: <a href=":csv_url">@csv_filename</a>', $targs), 'error');
        }
        else {
          $messenger->addMessage(t('Some rows failed to import, but unable to write error CSV to @csv_filepath', $targs), 'error');
        }
      }
      else {
        $messenger->addMessage(t('Some rows failed to import, but unable to create directory for error CSV at @csv_directory', $targs), 'error');
      }
    }

    if ($success) {
      $message = t('Import completed!');
      // Here we do something meaningful with the results.
      //$message = t("@count tasks were done.", array(
        //'@count' => count($results),
      //));
      \Drupal::messenger()->addMessage($message);
    }

    if (!empty($results['group'])) {
      $redirect_link = '/group/' . $results['group'] . '/members';
      return new RedirectResponse($redirect_link);
    }


  }

  /**
   * Remember the uploaded CSV filename.
   *
   * @TODO Is there a better way to pass a value from inception of the batch to
   * the finished function?
   */
  public static function csvimportRememberFilename($filename, &$context) {

    $context['results']['uploaded_filename'] = $filename;
  }


  /**
   * Process a single line.
   */
  public static function csvimportImportLine($line, $group, $group_roles, $user_roles, $line_headers, &$context) {

    
    $context['results']['rows_imported']++;
    $line = array_map('base64_decode', $line);

    $keyLine = [];
    $userArray = [];
    $profileArray = [];
    $group_member_import_fields = new GroupMemberImportFields();

    $context['results']['group'] = $group;

    // Simply show the import row count.
    $context['message'] = t('Importing row !c', ['!c' => $context['results']['rows_imported']]);
   
    $context['message'] = t('Importing %title', ['%title' => $line[0]]);
    

    // In order to slow importing and debug better, we can uncomment
    // this line to make each import slightly slower.
    // @codingStandardsIgnoreStart
    //usleep(2500);

    // @codingStandardsIgnoreEnd
    // Convert the line of the CSV file into a new user.
    // @codingStandardsIgnoreStart    

    // Default language & Timezone
    $language_default = \Drupal::languageManager()->getCurrentLanguage()->getId();
    $config = \Drupal::config('system.date');
    $timezone_default =  $config->get('timezone.default');


 
    // Bring column names and field values together  
    if ($context['results']['rows_imported'] > 1) {

      foreach ($line_headers as $header_key => $field_name) {
        
        foreach ($line as $line_key => $field_value) {

          if ( $header_key == $line_key ) {

            $keyLine[$field_name] = trim($field_value);

          }

        }

      } 

      foreach ($keyLine as $field => $field_data) {

        $field_info = explode('.', $field);
        $bundle = $field_info[0];
        $fieldName = $field_info[1];

        $field_type = $group_member_import_fields->getFieldType($bundle,$fieldName);

        \Drupal::logger('group_member_import')->notice('<pre><code>' . print_r($field_type, TRUE) . '</code></pre>');

        //Check any timezone field before running through field_types
        if ($fieldName === 'timezone') {

          if (!GroupMemberImportBatch::isValidTimezone($field_data)) {
            $timezone = $timezone_default;
          }
          else {
            $timezone = $field_data;
          }

          if ($bundle === 'user') {
            $userArray[$fieldName] = $timezone;
          }
          else {
            $profileArray[$bundle][$fieldName] = $timezone;
          }

        }
          
        switch($field_type) {

          case 'entity_reference':

            $field_settings = $group_member_import_fields->getFieldSettings($bundle,$fieldName);
            if ($field_settings['target_type'] == 'taxonomy_term') {
              $target_bundles = $field_settings['handler_settings']['target_bundles'];
              // If vocabulary field settings target is single, assume it.
              if (count($target_bundles) == 1 && !empty($field_data)) {
                $terms = $group_member_import_fields->get_term_reference($target_bundles[key($target_bundles)], $field_data);
              }
              // If not, assume vocabulary is added with ":" delimiter.
              else {
                $reference = explode(":", $field_data);
                if (is_array($reference) && $reference[0] != '') {
                  $terms = $group_member_import_fields->get_term_reference($reference[0], $reference[1]);
                }
              }
              if (!empty($terms)) {

                if ($bundle === 'user') {
                  $userArray[$fieldName] = $terms;
                }
                else {
                  $profileArray[$bundle][$fieldName] = $terms;
                }                
              }
            }
            elseif ($field_settings['target_type'] == 'user') {
              $userImportArray = explode(', ', $field_data);
              $users = $group_member_import_fields->get_user_info($userImportArray);              

              if ($bundle === 'user') {
                $userArray[$fieldName] = $users;
              }
              else {
                $profileArray[$bundle][$fieldName] = $users;
              } 

            }
            elseif ($field_settings['target_type'] == 'node') {
              $nodeImportArrays = explode(':', $field_data);
              $nodeReference1 = $group_member_import_fields->get_node_id($nodeImportArrays);

              if ($bundle === 'user') {
                $userArray[$fieldName] = $nodeReference1;
              }
              else {
                $profileArray[$bundle][$fieldName] = $nodeReference1;
              } 

            }

            break;

          case 'datetime':
            $dateArray = explode(':', $field_data);
            if (count($dateArray) > 1) {
              $dateTimeStamp = strtotime($field_data);
              $newDateString = date('Y-m-d\TH:i:s', $dateTimeStamp);
            }
            else {
              $dateTimeStamp = strtotime($field_data);
              $newDateString = date('Y-m-d', $dateTimeStamp);
            }

            if ($bundle === 'user') {
              $userArray[$fieldName] = ["value" => $newDateString];
            }
            else {
              $profileArray[$bundle][$fieldName] = ["value" => $newDateString];
            } 
              
            break;

          case 'timestamp':

            if ($bundle === 'user') {
              $userArray[$fieldName] = ["value" => $field_data];
            }
            else {
              $profileArray[$bundle][$fieldName] = ["value" => $field_data];
            }                 
                
            break;

          case 'language':  

            if (!GroupMemberImportBatch::isValidLangcode($field_data)) {
              $langcode = $language_default;
            }
            else {
              $langcode = $field_data;
            }
            
            if ($bundle === 'user') {
              $userArray[$fieldName] = $langcode;
            }
            else {
              $profileArray[$bundle][$fieldName] = $langcode;
            } 
          
            break;

          case 'list_string':
            
            $listArray = explode(",",$field_data);
            array_walk($listArray, 'trim');

            if ($bundle === 'user') {
              $userArray[$fieldName] = $listArray;
            }
            else {
              $profileArray[$bundle][$fieldName] = $listArray;
            } 

            break;

          case 'authored_by':
            $user_id = $group_member_import_fields->get_user_id($field_data);


            if ($bundle === 'user') {
              $userArray[$fieldName] = ($user_id > 0) ? $user_id : \Drupal::currentUser()->id();
            }
            else {
              $profileArray[$bundle][$fieldName] = ($user_id > 0) ? $user_id : \Drupal::currentUser()->id();
            }            

            break;

          case 'email':
            $email_value = str_replace(' ', '', $field_data);
            if (!GroupMemberImportBatch::validateEmail($email_value)) {
              $context['results']['failed_rows'][] = $line;
            }

            if ($bundle === 'user') {
              $userArray[$fieldName] = $email_value;
            }
            else {
              $profileArray[$bundle][$fieldName] = $email_value;
            }    

          case 'boolean':
            if ($bundle === 'user' && $field_name === 'status') {
              // Set the status only if valid
              if (GroupMemberImportBatch::validateStatus($field_value)) {
                $userArray[$fieldName] = $field_value;
              }              
            }
            elseif ($bundle === 'user' && $field_name != 'status') {
              $userArray[$fieldName] = $field_value;
            }
            else {
              $profileArray[$bundle][$fieldName] = $field_value;
            }
            

          default:
                      
            if ($bundle === 'user') {
              $userArray[$fieldName] = $field_data;
            }
            else {
              $profileArray[$bundle][$fieldName] = $field_data;
            }  

            break;
          
        }

        



        
    

        /*
        if ($bundle == 'user') {

          

          //$field_type = $group_member_import_fields->getFieldType($field_info[0],'init');
          //\Drupal::logger('group_member_import')->notice('<pre><code>' . print_r($field_type, TRUE) . '</code></pre>');

        }
        else {

          

          //$field_type = $group_member_import_fields->getFieldType($field_info[0],$field_info[1]);
          //\Drupal::logger('group_member_import')->notice('<pre><code>' . print_r($field_type, TRUE) . '</code></pre>');

        }

 */
       


      }

      
     

      
        

    }

    $importUser = $group_member_import_fields->importUser($userArray,$profileArray,$group, $group_roles, $user_roles);


  } 
 

  public static function isValidLangcode($langcode) {

    $allowed_languages = \Drupal::languageManager()->getLanguages();

    if(array_key_exists($langcode,$allowed_languages)) {
      return true;
    }

    return false;

  }

  public static function isValidTimezone($timezone) {

    $timezones = User::getAllowedTimezones();   

    if (in_array($timezone, $timezones)) {
      return true;
    }

    return false;

  }



  public static function validateEmail(string $email) {
    if (\Drupal::service('email.validator')->isValid($email)) {
      return true;
    }
    return false;
  }

  public static function validateStatus(string $status) {
    if ($status === "1" || $status === "0") {
      return true;
    }
    return false;
  }

  public static function cleanPassword(string $password) {
    return str_replace(' ','', $password);
  }

}
