<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';

class FakeEpp {
    public array $calls = [];
    public array $responses = [];
    public array $info = ['code'=>1000, 'registrant'=>'C1', 'contact'=>[['type'=>'tech','id'=>'C2']], 'authInfo'=>'old-code', 'ns'=>['ns1.example.net'], 'exDate'=>'2030-01-01', 'status'=>['ok']];
    public function __call(string $method, array $args) {
        $payload = $args[0] ?? [];
        $this->calls[] = [$method, $payload];
        if (isset($this->responses[$method])) { return $this->responses[$method]; }
        return match ($method) {
            'domainCheck' => ['code'=>1000,'domains'=>[$payload['domains'][0]=>['name'=>$payload['domains'][0], 'avail'=>true]]],
            'domainInfo' => $this->info,
            'contactInfo' => ['code'=>1000,'id'=>$payload['contact'],'name'=>'Existing Owner','org'=>'Existing Company','street1'=>'Existing Street','city'=>'Kyiv','postal'=>'01001','country'=>'UA','voice'=>'+380.501234567','email'=>'old@example.test'],
            'hostCheck' => ['code'=>1000,'hosts'=>[['name'=>$payload['hostname'],'avail'=>true]]],
            'domainCheckFee' => ['code'=>1000,'feeClass'=>'standard'],
            'domainCheckClaims' => ['code'=>1000,'claimKey'=>'claim-key','status'=>'1'],
            'hello' => '<epp><greeting/></epp>',
            'contactCreate' => ['code'=>1000,'id'=>$payload['id']],
            'domainCreate', 'domainCreateClaims', 'domainRenew' => ['code'=>1000,'exDate'=>'2030-01-01'],
            default => ['code'=>1000],
        };
    }
    // These methods are explicitly probed by the module.
    public function disableLogging() { $this->__call(__FUNCTION__, []); }
    public function setLogPath($path) { $this->__call(__FUNCTION__, [$path]); }
    public function setLoginObjects($objects) { $this->__call(__FUNCTION__, [$objects]); }
    public function setLoginExtensions($extensions) { $this->__call(__FUNCTION__, [$extensions]); }
    public function payloads($method): array { return array_column(array_values(array_filter($this->calls, fn ($call) => $call[0] === $method)), 1); }
}
class FakeFactory {
    public static FakeEpp $client;
    public static string $profile;
    public static function create($profile) { self::$profile = $profile; return self::$client; }
}
class_alias(FakeFactory::class, 'Pinga\\Tembo\\EppRegistryFactory');
require __DIR__.'/../EPP/EppRegistrar.php';

use App\Models\Domain;
use App\Models\RegistrarSettings;
use Modules\Registrars\EPP\EppRegistrar;
function module(array $settings = []): array {
    RegistrarSettings::$values = $settings + ['host'=>'epp.example.test','clid'=>'user','pw'=>'secret'];
    FakeFactory::$client = new FakeEpp();
    return [new EppRegistrar(), FakeFactory::$client, new Domain()];
}
$tests = [];
$tests['settings and PNLCS help'] = function () {
    [$m] = module(); check($m->getModuleName() === 'epp', 'PNLCS settings/sync key'); $fields = array_column($m->getConfigFields(), null, 'name');
    foreach (['host','port','tls_version','verify_peer','cafile','local_cert','local_pk','passphrase','clid','pw','registrarprefix','contact_postal_type','registry_profile','ns_mode','set_authinfo_on_info','login_objects','login_extensions','gtld','min_data_set','eurid_billing_contact','pl_contact_prefix','tmch_claims_period_active','enable_fee_extension','debug_log','debug_log_path'] as $key) {
        check(isset($fields[$key]['description']), 'Missing WHMCS setting/help '.$key);
    }
    check($fields['contact_postal_type']['options'] === ['int'=>'int','loc'=>'loc'], 'Postal options');
    check($fields['ns_mode']['default'] === 'hostObj', 'Default nameserver mode');
    check(str_contains($m->getConfigHelp(), 'creating and updating contacts'), 'Help is visible through PNLCS hook');
};
$tests['contact registration postal types and IDs'] = function () {
    foreach (['int','loc','invalid'] as $type) {
        [$m,$e,$d] = module(['contact_postal_type'=>$type,'registrarprefix'=>'test','contact_create_extra_json'=>'{"type":"override"}']);
        $result = $m->register($d, 1); check($result['success'], 'Registration: '.($result['message'] ?? ''));
        $contacts = $e->payloads('contactCreate'); check(count($contacts) === 4, 'Generic roles');
        foreach ($contacts as $c) {
            check($c['type'] === ($type === 'loc' ? 'loc' : 'int'), 'Postal type applied');
            check(str_ends_with($c['id'], '-TEST'), 'WHMCS registrar suffix');
            check($c['fullphonenumber'] === '+380.501234567', 'EPP telephone format');
        }
        check(count($e->payloads('hostCreate')) === 2, 'hostObj creates host objects');
        check(count($e->payloads('disconnect')) === 1, 'Session closed');
    }
};
$tests['profile role mapping and NASK prefix'] = function () {
    foreach (['EU'=>2,'SWITCH'=>2,'PL'=>1,'GE'=>1] as $profile=>$count) {
        [$m,$e,$d] = module(['registry_profile'=>$profile,'pl_contact_prefix'=>'NASK-','eurid_billing_contact'=>'BILL']);
        check($m->register($d,1)['success'], $profile.' registration');
        check(count($e->payloads('contactCreate')) === $count, $profile.' roles');
        if ($profile === 'PL') { check(str_starts_with($e->payloads('contactCreate')[0]['id'],'NASK-'), 'NASK prefix'); }
        if ($profile === 'EU') { check($e->payloads('domainCreate')[0]['contacts']['billing'] === 'BILL', 'EURid billing'); }
    }
};
$tests['minimum data set requires gTLD'] = function () {
    foreach (['0'=>4,'1'=>0] as $gtld=>$count) {
        [$m,$e,$d] = module(['gtld'=>(string)$gtld,'min_data_set'=>'1']);
        check($m->register($d,1)['success'], 'MDS registration');
        check(count($e->payloads('contactCreate')) === $count, 'MDS gating');
        check(isset($e->payloads('domainCreate')[0]['registrant']) === ($count > 0), 'MDS domain payload');
    }
};
$tests['contact update uses selected type and existing fields'] = function () {
    foreach (['int','loc'] as $type) {
        [$m,$e,$d] = module(['contact_postal_type'=>$type]);
        $result = $m->saveContactDetails($d, ['Registrant'=>['Email'=>'new@example.test','id'=>'ATTACKER','type'=>'override']]);
        check($result['success'], 'Contact update'); $p = $e->payloads('contactUpdate')[0];
        check($p['type'] === $type && $p['id'] === 'C1', 'Selected type and registry ID');
        check($p['address1'] === 'Existing Street' && $p['email'] === 'new@example.test', 'Partial update preserved fields');
        check($m->getContactDetails($d)['contacts']['registrant']['id'] === 'C1', 'Contact read');
    }
};
$tests['shared contacts are deduplicated and conflicting edits refused'] = function () {
    [$m,$e,$d] = module(); $e->info['contact'][0]['id']='C1';
    check($m->saveContactDetails($d,['registrant'=>['email'=>'x@example.test'],'tech'=>['email'=>'x@example.test']])['success'], 'Shared update');
    check(count($e->payloads('contactUpdate'))===1, 'Single write');
    $e->calls=[];
    check(!$m->saveContactDetails($d,['registrant'=>['email'=>'x@example.test'],'tech'=>['email'=>'y@example.test']])['success'], 'Conflicting update');
    check($e->payloads('contactUpdate')===[], 'Preflight before writes');
};
$tests['MDS contact edits never write to registry'] = function () {
    [$m,$e,$d] = module(['gtld'=>'1','min_data_set'=>'1']);
    check(!$m->saveContactDetails($d,['registrant'=>['email'=>'x@example.test']])['success'], 'Local contacts message');
    check($e->calls===[], 'No EPP writes');
};
$tests['AuthInfo request resets code with correct Tembo key'] = function () {
    [$m,$e,$d] = module(['set_authinfo_on_info'=>'1']);
    $first=$m->getEPPCode($d); $second=$m->getEPPCode($d);
    check($first!=='' && $second!==$first, 'Fresh transfer code on each request');
    check($e->payloads('domainInfo')===[], 'No info required before reset');
    check($e->payloads('domainUpdateAuthinfo')[0]['authInfo']===$first, 'Tembo authInfo key');
    check((bool)preg_match('/[A-Z]/',$first) && (bool)preg_match('/[a-z]/',$first) && (bool)preg_match('/[0-9]/',$first) && (bool)preg_match('/[^a-zA-Z0-9]/',$first), 'Password character classes');
    [$m,$e,$d]=module(); check($m->getEPPCode($d)==='old-code','Default reads existing code');
};
$tests['nameserver mode and native profile attributes'] = function () {
    foreach ([['ns_mode'=>'hostAttr'],['registry_profile'=>'EU']] as $settings) {
        [$m,$e,$d]=module($settings); check($m->register($d,1)['success'], 'hostAttr create');
        check($e->payloads('hostCheck')===[], 'No host object commands');
        check($e->payloads('domainCreate')[0]['nss'][0]['hostName']==='ns1.example.net','Inline payload');
        check(!$m->registerNameserver('ns1.example.net','192.0.2.1')['success'],'Child hosts unavailable');
    }
    [$m,$e,$d]=module(); check($m->saveNameservers($d,['ns3.example.net']), 'hostObj update');
    check($e->payloads('domainUpdateNS')[0]['ns1']==='ns3.example.net','hostObj update payload');
};
$tests['numeric registry failures propagate and close session'] = function () {
    [$m,$e,$d]=module(); $e->responses['domainCreate']=['code'=>2306,'msg'=>'Policy error'];
    check(!$m->register($d,1)['success'], 'Numeric error rejected');
    check($d->updates===[], 'No false local registration');
    check(count($e->payloads('disconnect'))===1,'Closed after failure');
    [$m,$e,$d]=module(); $e->responses['contactUpdate']=['code'=>2306,'msg'=>'only loc type is supported'];
    check(!$m->saveContactDetails($d,['registrant'=>['email'=>'new@example.test']])['success'],'Contact error propagated');
};
$tests['login failure and successful hello'] = function () {
    [$m,$e,$d]=module(); check($m->testConnection()['success'], 'Hello XML accepted');
    [$m,$e,$d]=module(); $e->responses['login']=['code'=>2200,'msg'=>'Authentication error'];
    check(!$m->testConnection()['success'],'Failed login');
    check(count($e->payloads('disconnect'))===1,'Closed failed login');
    check($e->payloads('hello')===[], 'No command after failed login');
};
$tests['TLS defaults and legacy/WHMCS values'] = function () {
    foreach (['1.2'=>'1.2','1.3'=>'1.3','on'=>'1.3','0'=>'1.2'] as $stored=>$expected) {
        [$m,$e]=module(['tls_version'=>(string)$stored]); $m->testConnection();
        check($e->payloads('connect')[0]['tls']===$expected,'TLS value '.$stored);
    }
};
$tests['premium checks require gTLD and block unauthorized charges'] = function () {
    [$m,$e,$d]=module(['gtld'=>'1','enable_fee_extension'=>'1']);
    $e->responses['domainCheckFee']=['code'=>1000,'feeClass'=>'premium'];
    check(!$m->checkAvailability('example.test')['available'],'Premium availability disabled');
    check(!$m->register($d,1)['success'],'Premium create disabled');
    check($e->payloads('contactCreate')===[],'No orphan contacts on premium refusal');
    [$m,$e,$d]=module(['gtld'=>'0','enable_fee_extension'=>'1']);
    check($m->register($d,1)['success'],'ccTLD registration');
    check($e->payloads('domainCheckFee')===[],'Fee gTLD gating');
};
$tests['TMCH requires accepted domain-bound unexpired notice'] = function () {
    [$m,$e,$d]=module(['tmch_claims_period_active'=>'1']);
    check(!$m->register($d,1)['success'],'Missing notice');
    check($e->payloads('contactCreate')===[],'Validate claims before remote creation');
    $notice=['domain'=>'example.test','accepted'=>true,'noticeID'=>'notice-1','notAfter'=>gmdate('c',time()+3600),'acceptedDate'=>gmdate('c',time()-60)];
    check($m->register($d,1,['tmch_claims'=>$notice])['success'],'Accepted notice');
    check($e->payloads('domainCreate')===[] && count($e->payloads('domainCreateClaims'))===1,'Claims create');
    $notice['notAfter']=gmdate('c',time()-1);
    check(!$m->register($d,1,['tmch_claims'=>$notice])['success'],'Expired notice');
};
foreach ($tests as $name=>$test) { $test(); echo "PASS $name\n"; }
echo count($tests)." regression scenarios passed.\n";
