<?PHP
/* ====================
Seditio - Website engine
Copyright Neocrome & Seditio Team
https://seditio.org

[BEGIN_SED]
File=plugins/feedback/feedback.ajx.php
Version=179
Updated=2022-jul-18
Type=Plugin
Author=Amro
Description=
[END_SED]

[BEGIN_SED_EXTPLUGIN]
Code=feedback
Part=Plugin
File=feedback.ajx
Hooks=ajax
Tags=
Minlevel=0
Order=10
[END_SED_EXTPLUGIN]
==================== */

if (!defined('SED_CODE')) {
  die('Wrong URL.');
}

header('Content-Type: application/json');

// processing only ajax requests (for other requests we complete the execution of the script)
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || $_SERVER['HTTP_X_REQUESTED_WITH'] != 'XMLHttpRequest') {
  exit();
}

// processing data sent only by the POST method (with other methods we complete the execution of the script)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  exit();
}

// file name for storing logs
define('LOG_FILE', 'plugins/feedback/logs/' . date('Y-m-d') . '.log');
// write warnings and errors to the log
define('HAS_WRITE_LOG', true);
// Should I check the captcha?
define('HAS_CHECK_CAPTCHA', true);
// Is it necessary to have files attached to the form?
define('HAS_ATTACH_REQUIRED', false);

const ALLOWED_EXTENSIONS = array(
  'jpg', 'jpeg', 'bmp', 'gif', 'png', 'doc', 'docx', 'xls', 'xlsx', 'pdf'
);

// allowed mime file types
const ALLOWED_MIME_TYPES = array(
  'image/jpeg',
  'image/gif',
  'image/png',
  'application/msword',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
  'application/vnd.ms-word.document.macroEnabled.12',
  'application/vnd.ms-word.template.macroEnabled.12',
  'application/vnd.ms-excel',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
  'application/vnd.ms-excel.sheet.macroEnabled.12',
  'application/vnd.ms-excel.template.macroEnabled.12',
  'application/vnd.ms-excel.addin.macroEnabled.12',
  'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
  'application/vnd.oasis.opendocument.text',
  'application/vnd.oasis.opendocument.spreadsheet',
  'application/vnd.oasis.opendocument.presentation',
  'application/pdf',
  'text/csv',
  'application/octet-stream'
);
// maximum allowed file size
define('MAX_FILE_SIZE', 2048 * 1024);
// directory for storing files
define('UPLOAD_PATH', 'plugins/feedback/uploads/');

// To send a letter
define('HAS_SEND_EMAIL', true);
// whether to add attached files to the body of the letter in the form of links
define('HAS_ATTACH_IN_BODY', false);
const EMAIL_SETTINGS = array(
  'addresses' => ['bilgi@***.com'], // who needs to send a letter
  'from' => ['root@***.org', 'Dimitri'], // from what email and name should the letter be sent?
  'subject' => 'Seditio.com.tr geri bildirim formundan mesaj', // letter subject
  'host' => 'mail.****.com', // SMTP-host
  'username' => 'bilgi@***.com', // // SMTP-user
  'password' => '******', // SMTP-password
  'port' => '587' // SMTP-port
);
define('HAS_SEND_NOTIFICATION', true);
define('BASE_URL', 'http://localhost/seditio/');
define('SUBJECT_FOR_CLIENT', 'Mesajınız iletildi');
//
define('HAS_WRITE_TXT', true);

function itc_log($message)
{
  if (HAS_WRITE_LOG) {
    error_log('Date:  ' . date('d.m.Y h:i:s') . '  |  ' . $message . PHP_EOL, 3, LOG_FILE);
  }
}

$data = [
  'errors' => [],
  'form' => [],
  'logs' => [],
  'result' => 'success'
];

$attachs = [];

/* STAGE 4 - DATA VALIDATION (FORM FIELDS VALUES) */

// name validation
if (!empty($_POST['name'])) {
  $data['form']['name'] = htmlspecialchars($_POST['name']);
} else {
  $data['result'] = 'error';
  $data['errors']['name'] = 'Fill in this field.';
  itc_log('The name field is not filled in.');
}

// email validation
if (!empty($_POST['email'])) {
  $data['form']['email'] = $_POST['email'];
  if (!filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
    $data['result'] = 'error';
    $data['errors']['email'] = 'Email is not correct.';
    itc_log('Email is not correct.');
  }
} else {
  $data['result'] = 'error';
  $data['errors']['email'] = 'Fill in this field.';
  itc_log('The email field is not filled in.');
}

// validation message
if (!empty($_POST['message'])) {
  $data['form']['message'] = htmlspecialchars($_POST['message']);
  if (mb_strlen($data['form']['message'], 'UTF-8') < 20) {
    $data['result'] = 'error';
    $data['errors']['message'] = 'Bu alan en az 20 karakter uzunluğunda olmalıdır.';
    itc_log('Mesaj alan en az 20 karakter uzunluğunda olmalıdır.');
  }
} else {
  $data['result'] = 'error';
  $data['errors']['message'] = 'Fill in this field.';
  itc_log('The message field is not filled in.');
}

// captcha check
if (HAS_CHECK_CAPTCHA) {
  session_start();
  if ($_POST['captcha'] === $_SESSION['captcha']) {
    $data['form']['captcha'] = $_POST['captcha'];
  } else {
    $data['result'] = 'error';
    $data['errors']['captcha'] = 'The code does not match the image.';
    itc_log('Captcha failed. Specified code ' . $captcha . ' does not match ' . $_SESSION['captcha']);
  }
}

// validation agree
if ($_POST['agree'] == 'true') {
  $data['form']['agree'] = true;
} else {
  $data['result'] = 'error';
  $data['errors']['agree'] = 'This checkbox must be checked.';
  itc_log('The agree field is not checked.');
}

// Validation of attached files
if (empty($_FILES['attach'])) {
  if (HAS_ATTACH_REQUIRED) {
    $data['result'] = 'error';
    $data['errors']['attach'] = 'Fill in this field.';
    itc_log('Files are not attached to the form.');
  }
} else {
  foreach ($_FILES['attach']['error'] as $key => $error) {
    if ($error == UPLOAD_ERR_OK) {
      $name = basename($_FILES['attach']['name'][$key]);
      $size = $_FILES['attach']['size'][$key];

      $mtype = mime_content_type($_FILES['attach']['tmp_name'][$key]);

      $ext = pathinfo($_FILES['attach']['tmp_name'][$key], PATHINFO_EXTENSION);
      $upl_extension = explode(".", $_FILES['attach']['tmp_name'][$key]);
      $ext2 = end($upl_extension);

      if (!in_array($mtype, ALLOWED_MIME_TYPES) && !(in_array($ext2, ALLOWED_EXTENSIONS) && ($ext == $ext2))) {
        $data['result'] = 'error';
        $data['errors']['attach'][$key] = 'The file is of an unauthorized type.';
        $data['errors']['attach']['mtype'] = $mtype;
        itc_log('Attached file ' . $name . ' has an unauthorized type.');
      } else if ($size > MAX_FILE_SIZE) {
        $data['result'] = 'error';
        $data['errors']['attach'][$key] = 'File size exceeds allowable size.';
        itc_log('file size ' . $name . ' exceeds permissible.');
      }
    }
  }
  if ($data['result'] === 'success') {
    // move the files to the UPLOAD_PATH folder
    foreach ($_FILES['attach']['name'] as $key => $attach) {
      $ext = mb_strtolower(pathinfo($_FILES['attach']['name'][$key], PATHINFO_EXTENSION));
		$name = basename($_FILES['attach']['name'][$key], $ext);
		$tmp = $_FILES['attach']['tmp_name'][$key];
		$newName = trim(rtrim($name, '.')) . '_' . uniqid() . '.' . $ext;
		$newName = sed_newname($newName);

      if (!move_uploaded_file($tmp, UPLOAD_PATH . $newName)) {
        $data['result'] = 'error';
        $data['errors']['attach'][$key] = 'Error uploading file.';
        itc_log('Error moving file ' . $name . '.');
      } else {
        $attachs[] = UPLOAD_PATH . $newName;
      }
    }
  }
}

use PHPMailer\PHPMailer\PHPMailer;
//use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require 'plugins/feedback/phpmailer/phpmailer/src/Exception.php';
require 'plugins/feedback/phpmailer/phpmailer/src/PHPMailer.php';
require 'plugins/feedback/phpmailer/phpmailer/src/SMTP.php';

if ($data['result'] == 'success' && HAS_SEND_EMAIL == true) {
  // we get the contents of the email template and replace it in it
  $template = file_get_contents('plugins/feedback/tpl/email.tpl');
  $search = ['%subject%', '%name%', '%email%', '%message%', '%date%'];
  $replace = [EMAIL_SETTINGS['subject'], $data['form']['name'], $data['form']['email'], $data['form']['message'], date('d.m.Y H:i')];
  $body = str_replace($search, $replace, $template);
  // adding files as links
  if (HAS_ATTACH_IN_BODY && count($attachs)) {
    $ul = 'Files attached to the form:<ul>';
    foreach ($attachs as $attach) {
      $href = str_replace($_SERVER['DOCUMENT_ROOT'], '', $attach);
      $name = basename($href);
      $ul .= '<li><a href="' . BASE_URL . $href . '" target="_blank">' . $name . '</a></li>';

      $data['href'][] = BASE_URL . $href;
    }
    $ul .= '</ul>';
    $body = str_replace('%attachs%', $ul, $body);
  } else {
    $body = str_replace('%attachs%', '', $body);
  }
  $mail = new PHPMailer();
  try {
    //Server settings
    //$mail->SMTPDebug = SMTP::DEBUG_SERVER;
    $mail->isSMTP();
    $mail->Host = EMAIL_SETTINGS['host'];
    $mail->SMTPAuth = true; 
    $mail->Username = EMAIL_SETTINGS['username'];
    $mail->Password = EMAIL_SETTINGS['password'];
    //$mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
    $mail->Port = EMAIL_SETTINGS['port'];
    //Recipients
    $mail->setFrom(EMAIL_SETTINGS['from'][0], EMAIL_SETTINGS['from'][1]);
    foreach (EMAIL_SETTINGS['addresses'] as $address) {
      $mail->addAddress(trim($address));
    }
    //Attachments
    if (!HAS_ATTACH_IN_BODY && count($attachs)) {
      foreach ($attachs as $attach) {
        $mail->addAttachment($attach);
      }
    }
    //Content
    $mail->CharSet = 'UTF-8';
    $mail->Encoding = 'base64';
    $mail->isHTML(true);
    $mail->Subject = EMAIL_SETTINGS['subject'];
    $mail->Body = $body;
    $mail->send();
    itc_log('Form submitted successfully.' . $mail->ErrorInfo);
  } catch (Exception $e) {
    $data['result'] = 'error';
    itc_log('Error sending email: ' . $mail->ErrorInfo);
  }
}

if ($data['result'] == 'success' && HAS_SEND_NOTIFICATION) {
  // cleaning addresses and attached files
  $mail->clearAllRecipients();
  $mail->clearAttachments();
  // we get the contents of the email template and replace the placeholders in it with their corresponding values
  $template = file_get_contents('plugins/feedback/tpl/email_client.tpl');
  $search = ['%subject%', '%name%', '%date%'];
  $replace = [SUBJECT_FOR_CLIENT, $data['form']['name'], date('d.m.Y H:i')];
  $body = str_replace($search, $replace, $template);
  try {
    // set parameters
    $mail->Subject = SUBJECT_FOR_CLIENT;
    $mail->Body = $body;
    $mail->addAddress($data['form']['email']);
    $mail->send();
    itc_log('Successfully sent a notification to the user.');
  } catch (Exception $e) {
    itc_log('Error sending notification to user: ' . $mail->ErrorInfo);
  }
}

if ($data['result'] == 'success' && HAS_WRITE_TXT) {
  $output = '=======' . date('d.m.Y H:i') . '=======';
  $output .= 'Name: ' . $data['form']['name'] . PHP_EOL;
  $output .= 'Email: ' . $data['form']['email'] . PHP_EOL;
  $output .= 'Message: ' . $data['form']['message'] . PHP_EOL;
  if (count($attachs)) {
    $output .= 'Files:' . PHP_EOL;
    foreach ($attachs as $attach) {
      $output .= $attach . PHP_EOL;
    }
  }
  $output = '=====================';
  error_log($output, 3, 'plugins/feedback/logs/forms.log');
}

echo json_encode($data);
exit();
