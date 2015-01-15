<?php
class GFBBSalesforceData {

    public static function update_table() {
        global $wpdb;
        $table_name = self::get_salesforce_table_name();

        if (!empty($wpdb->charset))
            $charset_collate = "DEFAULT CHARACTER SET $wpdb->charset";
        if (!empty($wpdb->collate))
            $charset_collate .= " COLLATE $wpdb->collate";

        $sql = "CREATE TABLE $table_name (
              id mediumint(8) unsigned not null auto_increment,
              form_id mediumint(8) unsigned not null,
              is_active tinyint(1) not null default 1,
              meta longtext,
              PRIMARY KEY  (id),
              KEY form_id (form_id)
            )$charset_collate;";

        require_once (ABSPATH . '/wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    public static function get_salesforce_table_name() {
        global $wpdb;
        return $wpdb->prefix . "rg_bb_salesforce";
    }

    public static function get_feed_types() {
        $feed_types = array_keys(self::get_feed_types_objects_list());
        sort($feed_types);
        return $feed_types;

        // $feedTypes = array('UserRegistration','Camperrelation',
        //     'Camperrelation','Authrelation','Authrelation2','Qualifications_c',
        //     //'CamperdetailsnonAuth',
        //     'CamperdetailsAuth',
        //     'VolunteerRegistration', 'VolunteerRegistrationInit',
        //     //'CampSpecificDetails',
        //     //'CampSpecificDetailsMerch',
        //     'Medicaldetails','MedicaldetailsVolunteer',
        //     'MedicalPrescription',
        //     'Confirm',
        //     ''
        //     );
        // return $feedTypes;
    }

    public static function get_feed_types_objects_list() {
        $feedObjectMap['UserRegistration'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/userreg'
                ),
                'objects' => array(
                        array(
                                'label' => 'user_contact',
                                'type' => 'Contact'
                        )
                )
        );
        $feedObjectMap['VolunteerRegistration'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/userreg'
                ),
                'objects' => array(
                        //                                     array('label'=>'user_contact','type'=>'Contact'),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );
        $feedObjectMap['VolunteerRegistrationInit'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/userreg'
                ),
                'objects' => array(
                        array(
                                'label' => 'user_contact',
                                'type' => 'Contact'
                        ),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );
        $feedObjectMap['VolunteerRegistration'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/userreg'
                ),
                'objects' => array(
                        //   array('label'=>'user_contact','type'=>'Contact'),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );

        $feedObjectMap['CamperRegistration'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/camperreg'
                ),
                'objects' => array(
                        array(
                                'label' => 'user_contact',
                                'type' => 'Contact'
                        ),
                        array(
                                'label' => 'camper_contact',
                                'type' => 'Contact'
                        ),
                        array(
                                'label' => 'relation',
                                'type' => 'Relationship__c'
                        ),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );
        $feedObjectMap['Camperrelation'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/camperrel'
                ),
                'objects' => array(
                        array(
                                'label' => 'camper_contact',
                                'type' => 'Contact'
                        ),
                        array(
                                'label' => 'relation',
                                'type' => 'Relationship__c'
                        ),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );
        $feedObjectMap['Authrelation'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/authrrel'
                ),
                'objects' => array(
                        //     array('label'=>'camper_contact','type'=>'Contact'),
                        array(
                                'label' => 'parent_contact',
                                'type' => 'Contact'
                        )
                //                                    array('label'=>'relation','type'=>'Relationship__c'),
                //                                    array('label'=>'registration','type'=>'Registration__c')
                )
        );
        $feedObjectMap['Authrelation2'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/authrrel'
                ),
                'objects' => array(
                        //     array('label'=>'camper_contact','type'=>'Contact'),
                        array(
                                'label' => 'parent_contact',
                                'type' => 'Contact'
                        )
                //                                    array('label'=>'relation','type'=>'Relationship__c'),
                //                                    array('label'=>'registration','type'=>'Registration__c')
                )
        );

        $feedObjectMap['CamperdetailsnonAuth'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/camper'
                ),
                'objects' => array(
                        array(
                                'label' => 'camper_contact',
                                'type' => 'Contact'
                        ),
                        //   array('label'=>'relation','type'=>'Relationship__c'),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );
        $feedObjectMap['CamperdetailsAuth'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/camper'
                ),
                'objects' => array(
                        array(
                                'label' => 'camper_contact',
                                'type' => 'Contact'
                        ),
                        //   array('label'=>'relation','type'=>'Relationship__c'),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );
        $feedObjectMap['CampSpecificDetails'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/registration'
                ),
                'objects' => array(
                        array(
                                'label' => 'camper_contact',
                                'type' => 'Contact'
                        ),
                        //   array('label'=>'relation','type'=>'Relationship__c'),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );
        $feedObjectMap['CampSpecificDetailsMerch'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/registration'
                ),
                'objects' => array(
                        array(
                                'label' => 'camper_contact',
                                'type' => 'Contact'
                        ),
                        //   array('label'=>'relation','type'=>'Relationship__c'),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );
        $feedObjectMap['Confirm'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/registration'
                ),
                'objects' => array(
                        //  array('label'=>'camper_contact','type'=>'Contact'),
                        //   array('label'=>'relation','type'=>'Relationship__c'),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );

        $feedObjectMap['Medicaldetails'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/registration'
                ),
                'objects' => array(
                        //   array('label'=>'camper_contact','type'=>'Contact'),
                        array(
                                'label' => 'diet',
                                'type' => 'Special_Diet__c'
                        ),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );

        $feedObjectMap['MedicaldetailsVolunteer'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/registration'
                ),
                'objects' => array(
                        //   array('label'=>'camper_contact','type'=>'Contact'),
                        array(
                                'label' => 'diet',
                                'type' => 'Special_Diet__c'
                        ),
                        array(
                                'label' => 'registration',
                                'type' => 'Registration__c'
                        )
                )
        );
        $feedObjectMap['MedicalPrescription'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/registration'
                ),
                'objects' => array(
                        //   array('label'=>'camper_contact','type'=>'Contact'),
                        array(
                                'label' => 'medication',
                                'type' => 'Camper_Medication__c'
                        ),
                        array(
                                'label' => 'medicationdetails',
                                'type' => 'Medication_Detail__c'
                        )
                )
        );
        $feedObjectMap['RefereeDetails'] = array(
                'api' => array(
                        'method' => 'POST',
                        'url' => '/registration'
                ),
                'objects' => array(
                        array(
                                'label' => 'referee',
                                'type' => 'Reference_Form__c'
                        )
                )
        );
        return $feedObjectMap;
    }

    public static function get_objects_list_for_feed_type($feed_type) {
        $feed_type_list = self::get_feed_types_objects_list();
        return isset($feed_type_list[$feed_type]) ? $feed_type_list[$feed_type] : false;
    }

    public static function get_feeds() {
        global $wpdb;
        $table_name = self::get_salesforce_table_name();
        $form_table_name = RGFormsModel::get_form_table_name();
        $sql = "SELECT s.id, s.is_active, s.form_id, s.meta, f.title as form_title
                FROM $table_name s
                INNER JOIN $form_table_name f ON s.form_id = f.id";

        $results = $wpdb->get_results($sql, ARRAY_A);

        $count = sizeof($results);
        for($i = 0; $i < $count; $i++) {
            $results[$i]["meta"] = maybe_unserialize($results[$i]["meta"]);
        }

        return $results;
    }

    public static function delete_feed($id) {
        global $wpdb;
        $table_name = self::get_salesforce_table_name();
        $wpdb->query($wpdb->prepare("DELETE FROM $table_name WHERE id=%s", $id));
    }

    public static function get_feed_by_form($form_id, $only_active = false) {
        global $wpdb;
        $table_name = self::get_salesforce_table_name();
        $active_clause = $only_active ? " AND is_active=1" : "";
        $sql = $wpdb->prepare("SELECT id, form_id, is_active, meta FROM $table_name WHERE form_id=%d $active_clause", $form_id);
        $results = $wpdb->get_results($sql, ARRAY_A);
        if (empty($results))
            return array();

        //Deserializing meta
        $count = sizeof($results);
        for($i = 0; $i < $count; $i++) {
            $results[$i]["meta"] = maybe_unserialize($results[$i]["meta"]);
        }
        return $results;
    }

    public static function get_feed($id) {
        global $wpdb;
        $table_name = self::get_salesforce_table_name();
        $sql = $wpdb->prepare("SELECT id, form_id, is_active, meta FROM $table_name WHERE id=%d", $id);
        $results = $wpdb->get_results($sql, ARRAY_A);
        if (empty($results))
            return array();

        $result = $results[0];
        $result["meta"] = maybe_unserialize($result["meta"]);
        return $result;
    }

    public static function update_feed($id, $form_id, $is_active, $setting) {
        global $wpdb;
        $table_name = self::get_salesforce_table_name();
        $setting = maybe_serialize($setting);
        if ($id == 0) {
            //insert
            $wpdb->insert($table_name, array("form_id" => $form_id, "is_active"=> $is_active, "meta" => $setting), array("%d", "%d", "%s"));
            $id = $wpdb->get_var("SELECT LAST_INSERT_ID()");
        } else {
            //update
            $wpdb->update($table_name, array("form_id" => $form_id, "is_active"=> $is_active, "meta" => $setting), array("id" => $id), array("%d", "%d", "%s"), array("%d"));
        }

        return $id;
    }

    public static function drop_tables() {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS " . self::get_salesforce_table_name());
    }
}
?>
