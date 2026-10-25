<?php
declare(strict_types=1);
// PHP 8.2+; install in www/3dmoymyach.ru/order; storage stays outside www/.
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
function answer(int $status, array $data): never { http_response_code($status); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE); exit; }
function inside(string $path, string $parent): bool { $path=str_replace('\\','/', $path); $parent=rtrim(str_replace('\\','/', $parent),'/'); if (PHP_OS_FAMILY==='Windows') {$path=strtolower($path);$parent=strtolower($parent);} return $path===$parent || str_starts_with($path,$parent.'/'); }
function textValue(array $data, string $key, int $max): string { $v=$data[$key]??''; if(!is_string($v)||strlen($v)>$max*4||!preg_match('//u',$v)||preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$v))answer(422,['error'=>'Проверьте текстовые поля.']); if(preg_match_all('/./us',$v)>$max)answer(422,['error'=>'Слишком длинный текст в одном из полей.']);return trim($v); }
function storeFile(string $path, string $data): void { if(file_put_contents($path,$data,LOCK_EX)!==strlen($data))throw new RuntimeException('write');chmod($path,0600); }
const MAX_PLAYERS=40;
const MAX_FILE=10485760;
const MAX_TOTAL=104857600;
$method=$_SERVER['REQUEST_METHOD']??'GET';
if(!in_array($method,['GET','POST'],true)){header('Allow: GET, POST');answer(405,['error'=>'Метод не поддерживается.']);}
if($method==='POST' && (int)($_SERVER['CONTENT_LENGTH']??0)>MAX_TOTAL+2097152)answer(413,['error'=>'Общий размер файлов превышает 100 МБ.']);
if($method==='POST' && !$_POST && (int)($_SERVER['CONTENT_LENGTH']??0)>0)answer(413,['error'=>'Превышен лимит загрузки на сервере. Выберите фотографии меньшего размера или обратитесь к менеджеру.']);
session_name('moymyach_form');
session_set_cookie_params(['lifetime'=>0,'path'=>rtrim(dirname($_SERVER['SCRIPT_NAME']),'/\\').'/','secure'=>(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off'),'httponly'=>true,'samesite'=>'Lax']);
ini_set('session.use_strict_mode','1');
if(!session_start())answer(503,['error'=>'Не удалось открыть сессию формы.']);
$_SESSION['csrf']??=bin2hex(random_bytes(32));
try {
 $storage=getenv('MOYMYACH_STORAGE_DIR');
 if(!$storage){
  // This package is installed at HOME/www/3dmoymyach.ru/order/.
  // Keep customer files outside the entire public www directory.
  if(basename(__DIR__)!=='order'||basename(dirname(__DIR__))!=='3dmoymyach.ru'||basename(dirname(__DIR__,2))!=='www')throw new RuntimeException('unexpected installation directory');
  $storage=dirname(__DIR__,3).'/moymyach-orders';
 }
 $parent=realpath(dirname($storage));$docroot=realpath($_SERVER['DOCUMENT_ROOT']??'');
 if(!$parent||!$docroot)throw new RuntimeException('storage path');
 $target=$parent.DIRECTORY_SEPARATOR.basename($storage);
 if(inside($target,$docroot)||inside($target,__DIR__))throw new RuntimeException('storage under webroot');
 if(!is_dir($target)&&!mkdir($target,0700))throw new RuntimeException('mkdir');
 $storage=realpath($target);
 if(!$storage||inside($storage,$docroot)||inside($storage,__DIR__)||!is_writable($storage))throw new RuntimeException('storage permissions');
 if(!class_exists('finfo'))throw new RuntimeException('fileinfo required');
}catch(Throwable $e){error_log('MoyMyach: storage configuration unavailable');answer(503,['error'=>'Приём заявок ещё не настроен. Обратитесь к менеджеру.']);}
if($method==='GET')answer(200,['csrf'=>$_SESSION['csrf'],'limits'=>['maxPlayers'=>MAX_PLAYERS,'maxFileBytes'=>MAX_FILE,'maxTotalBytes'=>MAX_TOTAL]]);
if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf']))answer(403,['error'=>'Сессия устарела. Откройте форму заново перед заполнением.']);
$requestId=$_POST['request_id']??'';
if(!is_string($requestId)||!preg_match('/^[a-f0-9-]{36}$/D',$requestId))answer(422,['error'=>'Неверный идентификатор отправки. Обновите страницу.']);
$raw=$_POST['payload']??'';
if(!is_string($raw)||strlen($raw)>131072)answer(422,['error'=>'Слишком большой объём текста.']);
$payload=json_decode($raw,true);
if(!is_array($payload)||($payload['confirmed']??false)!==true)answer(422,['error'=>'Подтвердите правильность фотографий и надписей.']);
$order=textValue($payload,'order',150);$inscription=textValue($payload,'inscription',3000);
$inscriptions=$payload['inscriptions']??($inscription!==''?[$inscription]:[]);
if(!is_array($inscriptions)||!array_is_list($inscriptions)||count($inscriptions)>20)answer(422,['error'=>'Можно добавить до 20 надписей.']);
$inscriptions=array_values(array_filter(array_map(fn($v)=>textValue(['text'=>$v],'text',isset($payload['inscriptions'])?1000:3000),$inscriptions),fn($v)=>$v!==''));
$inscription=implode("\n\n",array_map(fn($i,$v)=>'Надпись '.($i+1).":\n".$v,array_keys($inscriptions),$inscriptions));
$comments=textValue($payload,'comments',5000);$driveLink=textValue($payload,'driveLink',2000);
if($driveLink!==''){$url=parse_url($driveLink);if(!$url||!filter_var($driveLink,FILTER_VALIDATE_URL)||!in_array(strtolower($url['scheme']??''),['http','https'],true)||empty($url['host'])||isset($url['user'])||isset($url['pass']))answer(422,['error'=>'Укажите корректную ссылку на диск: https:// или http://.']);}
$customerRaw=$payload['customer']??null;$deliveryRaw=$payload['delivery']??null;$paymentRaw=$payload['payment']??null;
if(!is_array($customerRaw)||!is_array($deliveryRaw)||!is_array($paymentRaw))answer(422,['error'=>'Заполните контакты, доставку и способ оплаты.']);
$customer=[];foreach(['fullname'=>150,'phone'=>30,'email'=>254,'telegram'=>200,'max'=>200,'vk'=>200] as $k=>$max){$customer[$k]=textValue($customerRaw,$k,$max);}
if($customer['email']!==''&&!filter_var($customer['email'],FILTER_VALIDATE_EMAIL))answer(422,['error'=>'Проверьте адрес электронной почты.']);
$digits=preg_replace('/\D/','',$customer['phone']);
if($customer['fullname']===''||strlen($digits)<7||strlen($digits)>15||!preg_match('/^[+0-9\s().-]+$/D',$customer['phone']))answer(422,['error'=>'Укажите ФИО заказчика и корректный телефон.']);
$delivery=['method'=>textValue($deliveryRaw,'method',10),'address'=>textValue($deliveryRaw,'address',1000)];
if(!in_array($delivery['method'],['cdek','pickup'],true)||($delivery['method']==='cdek'&&$delivery['address']===''))answer(422,['error'=>'Укажите способ получения и адрес СДЭК.']);
if($delivery['method']==='pickup')$delivery['address']='';
$neededDate=textValue($payload,'neededDate',10);$date=DateTimeImmutable::createFromFormat('!Y-m-d',$neededDate);
if(!$date||$date->format('Y-m-d')!==$neededDate)answer(422,['error'=>'Укажите корректную дату, когда нужен мяч.']);
$payment=['type'=>textValue($paymentRaw,'type',15),'companyName'=>textValue($paymentRaw,'companyName',200)];
if(!in_array($payment['type'],['individual','company'],true))answer(422,['error'=>'Выберите физическое или юридическое лицо.']);
if($payment['type']==='individual')$payment['companyName']='';
$productionTerms='Срок производства — 2–4 рабочих дня после утверждения макета и оплаты. Время доставки не входит в срок производства.';
$people=$payload['players']??null;
if(!is_array($people)||!array_is_list($people)||count($people)<1||count($people)>MAX_PLAYERS)answer(422,['error'=>'Добавьте от 1 до 40 фотографий.']);
$validated=[];$keys=[];$total=0;$finfo=new finfo(FILEINFO_MIME_TYPE);
foreach($people as $i=>$person){
 if(!is_array($person))answer(422,['error'=>'Проверьте карточки фото.']);
 $key=$person['key']??'';
 if(!is_string($key)||!preg_match('/^[1-9][0-9]{0,5}$/D',$key)||isset($keys[$key]))answer(422,['error'=>'Повторяющаяся или неверная карточка.']);$keys[$key]=true;
 $name=textValue($person,'fullname',150);$number=textValue($person,'number',3);
 if($number!==''&&!preg_match('/^[0-9]{1,3}$/D',$number))answer(422,['error'=>'Если номер футболиста указан, он должен состоять из 1–3 цифр.']);
 $file=$_FILES['photo_'.$key]??null;
 if(!is_array($file)||!isset($file['error'])||is_array($file['error']))answer(422,['error'=>'Добавьте фото в каждую карточку.']);
 if($file['error']!==UPLOAD_ERR_OK)answer(422,['error'=>'Фото '.($i+1).' не загружено. Проверьте размер файла и попробуйте снова.']);
 if(!is_uploaded_file($file['tmp_name'])||$file['size']<1||$file['size']>MAX_FILE)answer(422,['error'=>'Каждая фотография должна быть размером до 10 МБ.']);
 $total+=$file['size'];if($total>MAX_TOTAL)answer(413,['error'=>'Общий размер файлов превышает 100 МБ.']);
 $mime=$finfo->file($file['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
 $dimensions=@getimagesize($file['tmp_name']);
 if(!$ext||!$dimensions||($dimensions['mime']??'')!==$mime||$dimensions[0]<1||$dimensions[1]<1||$dimensions[0]*$dimensions[1]>50000000)answer(422,['error'=>'Фото '.($i+1).': нужен JPG, PNG или WebP до 50 мегапикселей.']);
 $code='P'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT);
 $validated[]=['code'=>$code,'fullname'=>$name,'number'=>$number,'photo'=>$code.'.'.$ext,'originalName'=>basename(str_replace('\\','/',(string)$file['name'])),'bytes'=>$file['size'],'sha256'=>hash_file('sha256',$file['tmp_name']),'tmp'=>$file['tmp_name']];
}
$logoInput=$payload['logos']??[];
if(!is_array($logoInput)||!array_is_list($logoInput)||count($logoInput)>20)answer(422,['error'=>'Можно добавить до 20 логотипов.']);
$logos=[];$logoKeys=[];
foreach($logoInput as $i=>$entry){
 if(!is_array($entry))answer(422,['error'=>'Проверьте список логотипов.']);
 $key=$entry['key']??'';if(!is_string($key)||!preg_match('/^[1-9][0-9]{0,5}$/D',$key)||isset($logoKeys[$key]))answer(422,['error'=>'Неверная или повторяющаяся карточка логотипа.']);$logoKeys[$key]=true;
 $f=$_FILES['logo_'.$key]??null;
 if(!is_array($f)||($f['error']??-1)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||$f['size']<1||$f['size']>MAX_FILE)answer(422,['error'=>'Загрузите логотип '.($i+1).' размером до 10 МБ.']);
 $mime=$finfo->file($f['tmp_name']);$ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'][$mime]??null;
 if(!$ext)answer(422,['error'=>'Логотип '.($i+1).': нужен JPG, PNG, WebP или PDF.']);
 if($ext==='pdf'){$valid=file_get_contents($f['tmp_name'],false,null,0,5)==='%PDF-';}else{$im=@getimagesize($f['tmp_name']);$valid=$im&&$im[0]*$im[1]<=50000000&&($im['mime']??'')===$mime;}
 if(!$valid)answer(422,['error'=>'Не удалось прочитать логотип '.($i+1).'. Выберите другой файл.']);
 $total+=$f['size'];if($total>MAX_TOTAL)answer(413,['error'=>'Общий размер файлов превышает 100 МБ.']);
 $code='L'.str_pad((string)($i+1),2,'0',STR_PAD_LEFT);
 $logos[]=['code'=>$code,'file'=>$code.'.'.$ext,'originalName'=>basename(str_replace('\\','/',(string)$f['name'])),'bytes'=>$f['size'],'sha256'=>hash_file('sha256',$f['tmp_name']),'tmp'=>$f['tmp_name']];
}
$requisites=null;
if(isset($_FILES['requisites'])&&($_FILES['requisites']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE){
 if($payment['type']!=='company')answer(422,['error'=>'Прикрепить реквизиты можно для юридического лица.']);
 $f=$_FILES['requisites'];if(!is_array($f)||($f['error']??-1)!==UPLOAD_ERR_OK||!is_string($f['tmp_name']??null)||!is_uploaded_file($f['tmp_name'])||$f['size']<1||$f['size']>MAX_FILE)answer(422,['error'=>'Не удалось загрузить реквизиты. Лимит — 10 МБ.']);
 $ext=strtolower(pathinfo((string)$f['name'],PATHINFO_EXTENSION));$mime=$finfo->file($f['tmp_name']);$valid=false;
 if(in_array($ext,['jpg','jpeg','png'],true)){$im=@getimagesize($f['tmp_name']);$valid=$im&&$im[0]*$im[1]<=50000000&&$mime===($ext==='png'?'image/png':'image/jpeg');}
 elseif($ext==='pdf'){$valid=$mime==='application/pdf'&&file_get_contents($f['tmp_name'],false,null,0,5)==='%PDF-';}
 elseif(in_array($ext,['doc','xls'],true)){$valid=in_array($mime,['application/msword','application/vnd.ms-excel','application/x-ole-storage','application/CDFV2','application/vnd.ms-office'],true)&&bin2hex(file_get_contents($f['tmp_name'],false,null,0,8))==='d0cf11e0a1b11ae1';}
 elseif(in_array($ext,['docx','xlsx'],true)&&class_exists('ZipArchive')){$z=new ZipArchive();if($z->open($f['tmp_name'])===true){$valid=$z->locateName('[Content_Types].xml')!==false&&$z->locateName($ext==='docx'?'word/document.xml':'xl/workbook.xml')!==false;$z->close();}}
 if(!$valid)answer(422,['error'=>'Реквизиты: нужен корректный PDF, DOC, DOCX, XLS, XLSX, JPG или PNG. Если Word/Excel не принимается, сохраните реквизиты в PDF.']);
 $total+=$f['size'];if($total>MAX_TOTAL)answer(413,['error'=>'Общий размер файлов превышает 100 МБ.']);
 $requisites=['file'=>'requisites.'.($ext==='jpeg'?'jpg':$ext),'originalName'=>basename(str_replace('\\','/',(string)$f['name'])),'bytes'=>$f['size'],'sha256'=>hash_file('sha256',$f['tmp_name']),'tmp'=>$f['tmp_name']];
}
$fileCount=count(array_filter($_FILES,fn($f)=>($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE));
if($fileCount!==count($people)+count($logos)+($requisites?1:0))answer(422,['error'=>'Количество файлов не совпадает с заявкой.']);
$fingerprint=hash('sha256',json_encode([$order,$comments,$driveLink,$customer,$delivery,$neededDate,$payment,$requisites?array_diff_key($requisites,['tmp'=>true]):null,$inscriptions,array_map(fn($l)=>array_diff_key($l,['tmp'=>true]),$logos),array_map(fn($p)=>array_diff_key($p,['tmp'=>true]),$validated)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE));
// Persistent idempotency also survives a lost HTTP response or PHP restart.
$slot=$storage.'/.requests';if(!is_dir($slot)&&!mkdir($slot,0700))answer(503,['error'=>'Не удалось сохранить заявку. Попробуйте позже.']);
$requestKey=hash('sha256',$_SESSION['csrf'].':'.$requestId);
$receiptFile=$slot.'/'.$requestKey.'.json';
if(is_file($receiptFile)){$saved=json_decode((string)file_get_contents($receiptFile),true);if(($saved['fingerprint']??'')!==$fingerprint)answer(409,['error'=>'Данные изменились после отправки. Откройте форму заново для новой заявки.']);answer(200,['ok'=>true,'orderId'=>$saved['orderId']]);}
// Basic per-address hourly limit; never trust user-supplied forwarded headers.
$limdir=$storage.'/.limits';if(!is_dir($limdir))mkdir($limdir,0700);
$fh=fopen($limdir.'/'.hash('sha256',$_SERVER['REMOTE_ADDR']??'unknown').'.json','c+');
if(!$fh||!flock($fh,LOCK_EX))answer(503,['error'=>'Приём временно недоступен. Попробуйте позже.']);
$attempts=json_decode(stream_get_contents($fh),true)?:[];$attempts=array_values(array_filter($attempts,fn($t)=>is_int($t)&&$t>time()-3600));
if(count($attempts)>=20){flock($fh,LOCK_UN);fclose($fh);answer(429,['error'=>'Слишком много заявок. Попробуйте через час или свяжитесь с менеджером.']);}
$attempts[]=time();ftruncate($fh,0);rewind($fh);fwrite($fh,json_encode($attempts));flock($fh,LOCK_UN);fclose($fh);
$id='MM-'.gmdate('Ymd').'-'.strtoupper(substr($requestKey,0,10));$final=$storage.'/'.$id;$pending=$storage.'/.pending-'.$requestKey;$written=[];
// A previous commit may have completed just before a connection/process failure.
if(is_file($final.'/order.json')){$saved=json_decode((string)file_get_contents($final.'/order.json'),true);if(($saved['fingerprint']??'')===$fingerprint)answer(200,['ok'=>true,'orderId'=>$id]);answer(409,['error'=>'Повторная отправка содержит другие данные.']);}
try{
 if(!mkdir($pending,0700))throw new RuntimeException('pending');
 foreach($validated as &$person){$dest=$pending.'/'.$person['photo'];if(!move_uploaded_file($person['tmp'],$dest))throw new RuntimeException('upload');$written[]=$dest;chmod($dest,0600);unset($person['tmp']);}unset($person);
 foreach($logos as &$logo){$dest=$pending.'/'.$logo['file'];if(!move_uploaded_file($logo['tmp'],$dest))throw new RuntimeException('logo upload');$written[]=$dest;chmod($dest,0600);unset($logo['tmp']);}unset($logo);
 if($requisites){$dest=$pending.'/'.$requisites['file'];if(!move_uploaded_file($requisites['tmp'],$dest))throw new RuntimeException('requisites upload');$written[]=$dest;chmod($dest,0600);unset($requisites['tmp']);}
 $data=['orderId'=>$id,'createdAt'=>gmdate('c'),'order'=>$order,'comments'=>$comments,'driveLink'=>$driveLink,'customer'=>$customer,'delivery'=>$delivery,'neededDate'=>$neededDate,'payment'=>$payment,'requisites'=>$requisites,'productionTerms'=>$productionTerms,'inscription'=>$inscription,'inscriptions'=>$inscriptions,'logos'=>$logos,'confirmed'=>true,'fingerprint'=>$fingerprint,'players'=>$validated];
 $json=json_encode($data,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR);
 storeFile($pending.'/order.json',$json);$written[]=$pending.'/order.json';
 $brief="МОЙ МЯЧ — МАТЕРИАЛЫ ЗАКАЗА\nЗаявка: $id\nДата (UTC): ".$data['createdAt']."\nКоманда / заказ: $order\n\nЗАКАЗЧИК\nФИО: {$customer['fullname']}\nТелефон: {$customer['phone']}\nEmail: {$customer['email']}\nTelegram: {$customer['telegram']}\nMAX: {$customer['max']}\nВК: {$customer['vk']}\n\nПОЛУЧЕНИЕ И ОПЛАТА\nСпособ получения: ".($delivery['method']==='cdek'?'СДЭК':'Самовывоз')."\nАдрес: {$delivery['address']}\nКогда нужен мяч: $neededDate\n$productionTerms\nПлательщик: ".($payment['type']==='company'?'Юридическое лицо':'Физическое лицо')."\nОрганизация: {$payment['companyName']}\nРеквизиты: ".($requisites['file']??'не прикреплены')."\n\nНАДПИСИ НА МЯЧЕ\n$inscription\n\nФОТО\n";
 foreach($validated as $p){$brief.="\n{$p['code']} | ФИО: ".($p['fullname']!==''?$p['fullname']:'не указано')." | Номер: ".($p['number']!==''?$p['number']:'не указан')."\nФото: {$p['photo']}\n";}
 $brief.="\nКОММЕНТАРИИ\n".($comments!==''?$comments:'Не указаны')."\n\nССЫЛКА НА ОБЛАЧНЫЙ ДИСК\n".($driveLink!==''?$driveLink:'Не указана')."\n";
 $brief.="\nЛОГОТИПЫ\n";if(!$logos)$brief.="Не прикреплены\n";foreach($logos as $logo){$brief.="{$logo['code']} | {$logo['originalName']}\nФайл: {$logo['file']}\n";}
 storeFile($pending.'/Задание.txt',"\xEF\xBB\xBF".$brief);$written[]=$pending.'/Задание.txt';
 if(!rename($pending,$final))throw new RuntimeException('commit');
 // The committed folder is the source of truth even if the retry index cannot be written.
 try{storeFile($receiptFile,json_encode(['orderId'=>$id,'fingerprint'=>$fingerprint],JSON_THROW_ON_ERROR));}catch(Throwable $e){error_log('MoyMyach: retry index write failed');}
 answer(201,['ok'=>true,'orderId'=>$id]);
}catch(Throwable $e){foreach($written as $path){if(is_file($path))unlink($path);}if(is_dir($pending))@rmdir($pending);error_log('MoyMyach: order write failed');answer(503,['error'=>'Не удалось сохранить материалы. Попробуйте отправить ещё раз или обратитесь к менеджеру.']);}
