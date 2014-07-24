<?php

require_once('CRM/Core/Form.php');

class CRM_Cdntaxreceipts_Form_ViewTaxReceipt extends CRM_Core_Form {

  private $_reissue;
  private $_receipt;
  private $_method;
  private $_sendTarget;
  private $_pdfFile;

  /**
   * build all the data structures needed to build the form
   *
   * @return void
   * @access public
   */
  function preProcess() {

    //check for permission to view contributions
    if (!CRM_Core_Permission::check('access CiviContribute')) {
      CRM_Core_Error::fatal(ts('You do not have permission to access this page'));
    }
    parent::preProcess();

    $contributionId = CRM_Utils_Array::value('id', $_GET);
    $contactId = CRM_Utils_Array::value('cid', $_GET);

    if ( isset($contributionId) && isset($contactId) ) {
      $this->set('contribution_id', $contributionId);
      $this->set('contact_id', $contactId);
    }
    else {
      $contributionId = $this->get('contribution_id');
      $contactId = $this->get('contact_id');
    }

    // might be callback to retrieve the downloadable PDF file
    $download = CRM_Utils_Array::value('download', $_GET);
    if ( $download == 1 ) {
      $this->sendFile($contributionId, $contactId); // exits
    }

    $history = CRM_Cdntaxreceipts_Receipt::getIssueHistory($contributionId);
    $originalReceipt = $history['original'];

    if (isset($originalReceipt) ) {
      $this->_receipt = $originalReceipt;
      $this->_reissue = 1;
    }
    else {
      $this->_reissue = 0;
    }

    list($method, $email) = cdntaxreceipts_sendMethodForContact($contactId);
    $this->_method = $method;

    if ( $method == 'email' ) {
      $this->_sendTarget = $email;
    }

    // may need to offer a PDF file for download, if returning from form submission.
    // this sets up the form with proper JS to download the file, it doesn't actually send the file.
    // see ?download=1 for sending the file.
    $pdfDownload = CRM_Utils_Array::value('file', $_GET);
    if ( $pdfDownload == 1 ) {
      $this->_pdfFile = 1;
    }

  }

  /**
   * Build the form
   *
   * @access public
   *
   * @return void
   */
  function buildQuickForm() {

    if ( $this->_reissue ) {
      $receiptArray = $this->_receipt->toArray();
      $receipt_contributions = array();
      foreach ( $receiptArray['contributions'] as $c ) {
        $receipt_contributions[] = $c['contribution_id'];
      }

      CRM_Utils_System::setTitle('Tax Receipt');
      $buttonLabel = ts('Re-Issue Tax Receipt', array('domain' => 'org.civicrm.cdntaxreceipts'));
      $this->assign('reissue', 1);
      $this->assign('receipt', $receiptArray);
      $this->assign('contact_id', $receiptArray['contactId']);
      $this->assign('contribution_id', $this->get('contribution_id'));
      $this->assign('receipt_contributions', $receipt_contributions);
    }
    else {
      CRM_Utils_System::setTitle('Tax Receipt');
      $buttonLabel = ts('Issue Tax Receipt', array('domain' => 'org.civicrm.cdntaxreceipts'));
      $this->assign('reissue', 0);
    }

    $buttons = array();

    $buttons[] = array(
      'type' => 'cancel',
      'name' => ts('Back', array('domain' => 'org.civicrm.cdntaxreceipts')),
    );

    if (CRM_Core_Permission::check( 'issue cdn tax receipts' ) ) {
      $buttons[] = array(
        'type' => 'next',
        'name' => $buttonLabel,
        'isDefault' => TRUE,
        'js' => array('onclick' => "return submitOnce(this,'{$this->_name}','" . ts('Processing', array('domain' => 'org.civicrm.cdntaxreceipts')) . "');"),
      );
    }
    $this->addButtons($buttons);
    $this->assign('buttonLabel', $buttonLabel);

    $this->assign('method', $this->_method);

    if ( $this->_method == 'email' ) {
      $this->assign('receiptEmail', $this->_sendTarget);
    }

    if ( isset($this->_pdfFile) ) {
      $this->assign('pdf_file', $this->_pdfFile);
    }

  }

  /**
   * process the form after the input has been submitted and validated
   *
   * @access public
   *
   * @return None
   */

  function postProcess() {

    // ensure the user has permission to issue the tax receipt.
    if ( ! CRM_Core_Permission::check( 'issue cdn tax receipts' ) ) {
       CRM_Core_Error::fatal(ts('You do not have permission to access this page', array('domain' => 'org.civicrm.cdntaxreceipts')));
    }

    // load the contribution
    $contributionId = $this->get('contribution_id');
    $contactId = $this->get('contact_id');

    $contribution =  new CRM_Contribute_DAO_Contribution();
    $contribution->id = $contributionId;

    // We will refresh the form, with file stored in session if we need it.
    $urlParams = array('reset=1', 'cid='.$contactId, 'id='.$contributionId);

    if ( ! $contribution->find( TRUE ) ) {
      CRM_Core_Error::fatal( "CDNTaxReceipts: Could not retrieve details for this contribution" );
    }

    // issue tax receipt, or report error if ineligible
    if ( ! cdntaxreceipts_eligibleForReceipt($contribution->id) ) {
      $statusMsg = ts('This contribution is not tax deductible and/or not completed. No receipt has been issued.', array('domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus( $statusMsg );
    }
    else {
      $pdfGenerator = CRM_Cdntaxreceipts_PdfFactory::getPDFLib();

      if ($this->_reissue) {
        $receipt = $this->_receipt;
      }
      else {
        $receipt = CRM_Cdntaxreceipts_Receipt::createFromContribution($contribution);
        if ($receipt == NULL) {
          CRM_Core_Error::fatal( "CDNTaxReceipts: Could not retrieve details for this contribution: %1", array(1 => $contributionId));
        }
      }

      $result = $receipt->issue($pdfGenerator, NULL);
      $method = $receipt->getIssueMethod();
      $pdf_file = $receipt->getFileName();

      if ( $method == 'print' && isset($pdfGenerator) ) {
        $session = CRM_Core_Session::singleton();
        $session->set("pdf_file_". $contributionId . "_" . $contactId, $pdf_file, 'cdntaxreceipts');
        $urlParams[] = 'file=1';
      }

      if ( $result == TRUE ) {
        if ( $method == 'email' ) {
          $statusMsg = ts('Tax Receipt has been emailed to the contributor.', array('domain' => 'org.civicrm.cdntaxreceipts'));
        }
        else {
          $statusMsg = ts('Tax Receipt has been generated for printing.', array('domain' => 'org.civicrm.cdntaxreceipts'));
        }
        CRM_Core_Session::setStatus( $statusMsg );
      }
      else {
        $statusMsg = ts('Encountered an error. Tax receipt has not been issued.', array('domain' => 'org.civicrm.cdntaxreceipts'));
        CRM_Core_Session::setStatus( $statusMsg );
        unset($pdfGenerator);
      }
    }

    $url = CRM_Utils_System::url(CRM_Utils_System::currentPath(), implode('&', $urlParams));
    CRM_Utils_System::redirect($url);
  }

  function sendFile($contributionId, $contactId) {

    $session = CRM_Core_Session::singleton();
    $filename = $session->get("pdf_file_" . $contributionId . "_" . $contactId, 'cdntaxreceipts');

    if ( $filename && file_exists($filename) ) {
      // set up headers and stream the file
      header('Content-Description: File Transfer');
      header('Content-Type: application/octet-stream');
      header('Content-Disposition: attachment; filename='.basename($filename));
      header('Content-Transfer-Encoding: binary');
      header('Expires: 0');
      header('Cache-Control: must-revalidate');
      header('Pragma: public');
      header('Content-Length: ' . filesize($filename));
      ob_clean();
      flush();
      readfile($filename);

      // clean up -- not cleaning up session and file because IE may reload the page
      // after displaying a security warning for the download. otherwise I would want
      // to delete the file once it has been downloaded.  hook_cron() cleans up after us
      // for now.

      // $session->set('pdf_file', NULL, 'cdntaxreceipts');
      // unlink($filename);
      CRM_Utils_System::civiExit();
    }
    else {
      $statusMsg = ts('File has expired. Please retrieve receipt from the email archive.', array('domain' => 'org.civicrm.cdntaxreceipts'));
      CRM_Core_Session::setStatus( $statusMsg );
    }
  }

}

