<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
require __DIR__.'/../EPP/namingo/vendor/autoload.php';
require __DIR__.'/../EPP/EppRegistrar.php';

use Modules\Registrars\EPP\EppRegistrar;
use App\Models\RegistrarSettings;
use App\Models\Domain;

/** Exercise the installed Tembo serializers, replacing only socket I/O. */
class RecordingEpp extends \Pinga\Tembo\Registries\GenericEpp {
    public array $requests = [];
    public function __construct() { parent::__construct(); $this->isLoggedIn = true; $this->prefix = 'test'; }
    public function writeRequest($xml) {
        $this->requests[] = $xml;
        check(simplexml_load_string($xml) !== false, 'Valid request XML');
        $data = '';
        if (str_contains($xml, '<domain:info')) {
            $data = '<resData><domain:infData xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">'
                .'<domain:name>example.test</domain:name><domain:status s="ok"/><domain:registrant>C1</domain:registrant>'
                .'<domain:contact type="tech">C2</domain:contact><domain:ns>'
                .'<domain:hostAttr><domain:hostName>ns1.example.net</domain:hostName></domain:hostAttr>'
                .'</domain:ns><domain:exDate>2030-01-01T00:00:00Z</domain:exDate>'
                .'<domain:authInfo><domain:pw>old-code</domain:pw></domain:authInfo></domain:infData></resData>';
        } elseif (str_contains($xml, '<contact:create')) {
            $data = '<resData><contact:creData xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">'
                .'<contact:id>C1</contact:id><contact:crDate>2026-01-01T00:00:00Z</contact:crDate></contact:creData></resData>';
        }
        return simplexml_load_string('<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"><response><result code="1000"><msg>Success</msg></result>'.$data.'</response></epp>');
    }
}
function invoke(EppRegistrar $module, string $name, ...$args) {
    return (new ReflectionMethod($module, $name))->invoke($module, ...$args);
}
foreach (['int','loc'] as $type) {
    RegistrarSettings::$values=['contact_postal_type'=>$type]; $module=new EppRegistrar(); $epp=new RecordingEpp();
    invoke($module,'createContacts',$epp,new Domain(),[]);
    check(count($epp->requests)===4, 'All generic roles serialized');
    foreach ($epp->requests as $xml) { check(str_contains($xml,'<contact:postalInfo type="'.$type.'">'), 'Create XML '.$type); }
    $details=invoke($module,'contactDetailsFromInfo',['name'=>'Ілля Example','email'=>'person@example.test','street1'=>'Test & Road']);
    $details['id']='C1'; $details['type']=invoke($module,'contactPostalType');
    $result=$epp->contactUpdate($details);
    check(($result['code']??0)===1000, 'Tembo update success');
    $xml=end($epp->requests);
    check(str_contains($xml,'<contact:postalInfo type="'.$type.'">'), 'Update XML '.$type);
    check(str_contains($xml,'Test &amp; Road'), 'Contact XML escaping');
    echo "PASS real Tembo contact:create/contact:update XML ($type)\n";
}
RegistrarSettings::$values=['ns_mode'=>'hostAttr']; $module=new EppRegistrar(); $epp=new RecordingEpp();
$info=invoke($module,'domainInfo',$epp,'example.test');
check($info['ns']===['ns1.example.net'] && $info['registrant']==='C1' && $info['authInfo']==='old-code','hostAttr info mapping');
check($info['contact'][0]['id']==='C2' && $info['status']===['ok'],'hostAttr contacts and statuses');
$result=invoke($module,'updateHostAttributes',$epp,'example.test',['ns2.example.net']);
check($result['code']===1000,'hostAttr update');
$xml=end($epp->requests);
check(str_contains($xml,'<domain:add>') && str_contains($xml,'<domain:rem>'),'NS add/remove');
check(str_contains($xml,'<domain:hostName>ns2.example.net</domain:hostName>'),'New hostAttr');
check(!str_contains($xml,'hostObj'),'No hostObj');
$before=count($epp->requests);
invoke($module,'updateHostAttributes',$epp,'example.test',['ns1.example.net']);
check(count($epp->requests)===$before+1,'Unchanged nameservers only read');
echo "PASS real Tembo hostAttr info/update XML\n";
$epp->domainUpdateAuthinfo(['domainname'=>'example.test','authInfo'=>'Abc123!@']);
check(str_contains(end($epp->requests),'<domain:pw>Abc123!@</domain:pw>'),'AuthInfo wire value');
echo "PASS real Tembo AuthInfo XML\n";
