<?php
/*-------------------------------------------------------+
| YfC SAGE Exporter                                      |
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

  public static $tax_rates = NULL;

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

    self::$tax_rates = array(
      'T0' => 0.0,
      'T1' => 0.2,
      'T2' => 0.0,
      'T5' => 0.05,
      'T9' => 0.0,
      );
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
       {$custom_field['column_name']}        AS trxn_ledgercode,
       ft.trxn_date                          AS trxn_date,
       batch.title                           AS trxn_batch_title,
       batch.id                              AS trxn_batch_id,
       ty.name                               AS trxn_financial_type,
       ct.id                                 AS contribution_id,
       ct.receive_date                       AS contribution_receive_date,
       ct.contact_id                         AS contact_id,
       co.display_name                       AS contact_display_name,
       ft.total_amount                       AS trxn_amount

      FROM civicrm_batch batch 
      LEFT JOIN civicrm_entity_batch          eb  ON eb.batch_id = batch.id
      LEFT JOIN civicrm_financial_trxn        ft  ON (eb.entity_id = ft.id AND eb.entity_table = 'civicrm_financial_trxn')
      LEFT JOIN civicrm_entity_financial_trxn eft ON (eft.financial_trxn_id = ft.id AND eft.entity_table = 'civicrm_contribution')
      LEFT JOIN civicrm_contribution          ct  ON ct.id = eft.entity_id
      LEFT JOIN civicrm_contact               co  ON co.id = ct.contact_id
      LEFT JOIN civicrm_campaign              cp  ON cp.id = ct.campaign_id
      LEFT JOIN civicrm_financial_type        ty  ON ty.id = ct.financial_type_id
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

    fputcsv($out, $export['headers']);
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
        // derive tax code and tax rate
        if (preg_match('#\((?P<tax_code>T\d)\)#', $dao->trxn_financial_type, $match)) {
          $tax_code = $match['tax_code'];
          $tax_rate = self::$tax_rates[$tax_code];
        } else {
          $tax_code = '';
          $tax_rate = 0.0;
        }

        // negative amounts should be cancelled as BP
        if ($dao->trxn_amount < 0.0) {
          $type = 'BP';
        } else {
          $type = 'BR';
        }

        // do some sanity checks
        if (empty($dao->trxn_ledgercode)) {
          throw new Exception("Contribution [{$dao->contribution_id}] has no ledger code. Please adjust destination code!");
        }
        if (empty($tax_code)) {
          throw new Exception("Contribution [{$dao->contribution_id}] has no tax code. Please adjust financial type!");
        }

        $net_amount = number_format(($dao->trxn_amount / (1.0 + $tax_rate)), 2);
        $financialItems[] = array(
          'Type'                 => $type,
          'Account Reference'    => '04120',
          'Nominal A/C Ref'      => $dao->trxn_ledgercode,
          'Department Code'      => '',
          'Date'                 => date('Y-m-d', strtotime($dao->trxn_date)),
          'Reference'            => $dao->trxn_batch_title,
          'Details'              => "{$dao->trxn_financial_type} from {$dao->contact_display_name} [{$dao->contact_id}]",
          'Net Amount'           => $net_amount,
          'Tax Code'             => $tax_code,
          'Tax Amount'           => ($dao->trxn_amount - $net_amount),
          'Exchange Rate'        => '',
          'Extra Reference'      => "ID{$dao->contribution_id} " . date('Y-m-d', strtotime($dao->contribution_receive_date)),
          'User Name'            => '',
          'Project Refn'         => '',
          'Cost Code Refn'       => '',
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
