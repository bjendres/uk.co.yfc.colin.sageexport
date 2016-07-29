<?php
/*-------------------------------------------------------+
| SAGE Exporter                                          |
| Copyright (C) 2016 SYSTOPIA                            |
| Author: B. Endres (endres -at- systopia.de)            |
| http://www.systopia.de/                                |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

/**
 * @link http://wiki.civicrm.org/confluence/display/CRM/CiviAccounts+Specifications+-++Batches#CiviAccountsSpecifications-Batches-%C2%A0Overviewofimplementation
 */
class CRM_Financial_BAO_ExportFormat_SAGE extends CRM_Financial_BAO_ExportFormat {

  // For this phase, we always output these records too so that there isn't data referenced in the journal entries that isn't defined anywhere.
  // Possibly in the future this could be selected by the user.
  public static $complementaryTables = array(
    'ACCNT',
    'CUST',
  );

  /**
   * Class constructor.
   */
  public function __construct() {
    parent::__construct();
  }

  /**
   * @param array $exportParams
   */
  public function export($exportParams) {
    $export = parent::export($exportParams);

    // Save the file in the public directory
    $fileName = self::putFile($export);

    foreach (self::$complementaryTables as $rct) {
      $func = "export{$rct}";
      $this->$func();
    }

    // now do general journal entries
    $this->exportTRANS();

    $this->output($fileName);
  }

  /**
   * @param int $batchId
   *
   * @return Object
   */
  public function generateExportQuery($batchId) {
    // look up the necessary custom fields
    $ledger_code_field_name = 'ledgercode';
    $custom_field = civicrm_api3('CustomField', 'getsingle', array('name' => $ledger_code_field_name));
    $custom_group = civicrm_api3('CustomGroup', 'getsingle', array('id' => $custom_field['custom_group_id']));

    // compile the query
    $sql = "SELECT
      'BR'                                   AS field_1_BR,
      '4120'                                 AS field_2_4120,
       {$custom_field['column_name']}        AS field_3_ledgercode,
       NULL                                  AS field_4_empty,
       DATE_FORMAT(ft.trxn_date, '%e%m%y')   AS field_5_date,
       NULL                                  AS field_6_empty,
       batch.title                           AS field_7_batchname,
       ft.total_amount                       AS field_8_amount

      FROM civicrm_batch batch 
      LEFT JOIN civicrm_entity_batch          eb  ON eb.batch_id = batch.id
      LEFT JOIN civicrm_financial_trxn        ft  ON (eb.entity_id = ft.id AND eb.entity_table = 'civicrm_financial_trxn')
      LEFT JOIN civicrm_entity_financial_trxn eft ON (eft.financial_trxn_id = ft.id AND eft.entity_table = 'civicrm_contribution')
      LEFT JOIN civicrm_contribution          ct  ON ct.id = eft.entity_id
      LEFT JOIN civicrm_campaign              cp  ON cp.id = ct.campaign_id
      LEFT JOIN {$custom_group['table_name']} dc  ON dc.entity_id = cp.id
      WHERE batch.id = ( %1 )";

    CRM_Utils_Hook::batchQuery($sql);
    $params = array(1 => array($batchId, 'Integer'));
    $dao = CRM_Core_DAO::executeQuery($sql, $params);

    return $dao;
  }

  /**
   * @param $export
   *
   * @return string
   */
  public function putFile($export) {
    $config = CRM_Core_Config::singleton();
    $fileName = $config->uploadDir . 'SAGE_Export_' . $this->_batchIds . '_' . date('YmdHis') . '.' . $this->getFileExtension();
    $this->_downloadFile[] = $config->customFileUploadDir . CRM_Utils_File::cleanFileName(basename($fileName));
    $out = fopen($fileName, 'w');
    // fputcsv($out, $export['headers']);
    unset($export['headers']);
    if (!empty($export)) {
      foreach ($export as $fields) {
        fputcsv($out, $fields);
      }
      fclose($out);
    }
    return $fileName;
  }

  /**
   * Format table headers.
   *
   * @param array $values
   * @return array
   */
  public function formatHeaders($values) {
    $arrayKeys = array_keys($values);
    $headers = '';
    if (!empty($arrayKeys)) {
      foreach ($values[$arrayKeys[0]] as $title => $value) {
        $headers[] = $title;
      }
    }
    return $headers;
  }



  /**
   * Generate SAGE CSV array for export.
   * (CiviCRM 4.6 compatibility method)
   * 
   * @param array $export
   */
  public function makeSAGE($export) {  
    return $this->makeExport($export);
  }
  
  /**
   * Generate SAGE CSV array for export.
   *
   * @param array $export
   */
  public function makeExport($export) {
    // getting data from admin page
    $prefixValue = CRM_Core_BAO_Setting::getItem(CRM_Core_BAO_Setting::CONTRIBUTE_PREFERENCES_NAME, 'contribution_invoice_settings');

    foreach ($export as $batchId => $dao) {
      $financialItems = array();
      $this->_batchIds = $batchId;

      $batchItems = array();
      $queryResults = array();

      while ($dao->fetch()) {
        $financialItems[] = array(
          'field_1_BR'         => $dao->field_1_BR,
          'field_2_4120'       => $dao->field_2_4120,
          'field_3_ledgercode' => $dao->field_3_ledgercode,
          'field_4_empty'      => $dao->field_4_empty,
          'field_5_date'       => $dao->field_5_date,
          'field_6_empty'      => $dao->field_6_empty,
          'field_7_batchname'  => $dao->field_7_batchname,
          'field_8_amount'     => $dao->field_8_amount,
        );
        end($financialItems);

        $batchItems[] = &$financialItems[key($financialItems)];
        $queryResults[] = get_object_vars($dao);
      }

      CRM_Utils_Hook::batchItems($queryResults, $batchItems);

      $financialItems['headers'] = self::formatHeaders($financialItems);
      self::export($financialItems);
    }
    parent::initiateDownload();
  }

  /**
   * @return string
   */
  public function getFileExtension() {
    return 'csv';
  }

  public function exportACCNT() {
  }

  public function exportCUST() {
  }

  public function exportTRANS() {
  }

}
