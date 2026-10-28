// Windows can create junctions without the privilege needed for file symlinks.
const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),assert=require('node:assert/strict');
const php=process.argv[2];
if(process.platform!=='win32'||!php)throw new Error('Usage on Windows: node tests/junctions.cjs <php.exe>');
const temp=path.resolve(__dirname,'.tmp');fs.mkdirSync(temp,{recursive:true});
const fixture=fs.mkdtempSync(path.join(temp,'junction-'));
const normal=path.join(fixture,'normal'),linked=path.join(fixture,'linked'),outside=path.join(fixture,'outside');
for(const dir of [normal,linked,outside])fs.mkdirSync(dir);
fs.mkdirSync(path.join(normal,'ad'));
fs.symlinkSync(outside,path.join(normal,'ad','escape'),'junction');
fs.symlinkSync(outside,path.join(linked,'ad'),'junction');
const code=`define('ABSPATH',$argv[1].'/');class WP_Error{public $code;function __construct($code,$msg,$data=null){$this->code=$code;}}function is_wp_error($x){return $x instanceof WP_Error;}require $argv[2].'/keha-mcp/includes/helpers.php';require $argv[2].'/keha-mcp/includes/abilities-ad.php';$root=keha_mcp_ad_root();if($argv[3]==='root'){if(!is_wp_error($root)){exit(1);}}else{if(is_wp_error($root)){exit(2);}$r=keha_mcp_ad_path($root,'escape/outside.js',false);if(!is_wp_error($r)){exit(3);}}echo 'PASS: junction blocked ('.$argv[3].')'.PHP_EOL;`;
try{
  for(const [web,mode] of [[normal,'parent'],[linked,'root']]){
    const result=cp.spawnSync(php,['-r',code,web,path.resolve(__dirname,'..'),mode],{encoding:'utf8'});
    assert.equal(result.status,0,result.stderr||result.stdout);process.stdout.write(result.stdout);
  }
}finally{
  // rmdir removes only each junction entry, never its target.
  fs.rmdirSync(path.join(normal,'ad','escape'));fs.rmdirSync(path.join(linked,'ad'));
  fs.rmdirSync(path.join(normal,'ad'));for(const dir of [normal,linked,outside])fs.rmdirSync(dir);fs.rmdirSync(fixture);
}
