// Uses an externally installed Playwright through NODE_PATH; no application dependency added.
const {chromium}=require('playwright'),{execFileSync}=require('child_process'),assert=require('assert/strict'),path=require('path'),crypto=require('crypto');
const root=path.resolve(__dirname,'..'),base=process.env.CERTREEFY_TEST_URL||'http://localhost/certreefy/pages/';
const php=(code,args=[])=>execFileSync(process.env.CERTREEFY_PHP||'C:/xampp/php/php.exe',['-r',code,...args.map(String)],{cwd:root,encoding:'utf8'}).trim();
const query=(sql,params=[])=>JSON.parse(php('require $argv[1];$q=$pdo->prepare($argv[2]);$q->execute(json_decode($argv[3],true));echo json_encode($q->fetchAll());',['config/config.php',sql,JSON.stringify(params)]));
(async()=>{
 const suffix=crypto.randomBytes(8).toString('hex'),username='revision_'+suffix,providerName='Provider probe '+suffix;
 const hash=php('echo password_hash(bin2hex(random_bytes(20)),PASSWORD_DEFAULT);');
 const subtype=php("require $argv[1];require $argv[2];echo permit_matrix_category('private_lands')['subtypes'][0];",['config/config.php','includes/permit_matrix.php']);
 query("INSERT INTO tbl_users (fname,lname,email,username,password,role,status,applicant_category,applicant_subtype,contact,address) VALUES ('Revision','Probe',?,?,?,'community','active','private_lands',?,'09999999999','Temporary test address')",[username+'@example.invalid',username,hash,subtype]);
 const owner=Number(query('SELECT id FROM tbl_users WHERE username=?',[username])[0].id),sessions=[];let browser,requestId=0;
 try{
  browser=await chromium.launch({channel:'msedge',headless:true});
  async function context(role,id){
   id=id||Number(query("SELECT id FROM tbl_users WHERE role=? AND status='active' ORDER BY id LIMIT 1",[role])[0].id);
   const sid='revision-'+suffix+'-'+role;php("session_id($argv[1]);require $argv[2];$_SESSION['id']=(int)$argv[3];session_write_close();",[sid,'config/config.php',id]);sessions.push(sid);
   const context=await browser.newContext({viewport:{width:1536,height:1000}});await context.addCookies([{name:'PHPSESSID',value:sid,domain:'localhost',path:'/'}]);return context;
  }
  const client=await context('community',owner),ems=await context('ems'),p=await client.newPage(),staff=await ems.newPage(),errors=[];
  for(const page of [p,staff])page.on('pageerror',e=>errors.push(e.message));
  await p.goto(base+'community/permit-application.php',{waitUntil:'networkidle'});
  await p.click('#application-next');await p.click('#application-next');
  await p.selectOption('#district','District 4');await p.selectOption('#municipality','Santa Cruz');await p.selectOption('#barangay','Bubukal');
  assert(!(await p.locator('#municipality option').allTextContents()).includes('City of Calamba'));
  await p.selectOption('#municipality','Pagsanjan');assert.equal(await p.locator('#barangay').inputValue(),'');assert((await p.locator('#barangay option').allTextContents()).includes('Sabang'));
  await p.selectOption('#municipality','Santa Cruz');await p.selectOption('#barangay','Bubukal');await p.click('#application-next');
  await p.selectOption('#purpose_option',{index:1});await p.fill('#area_value','10000');await p.selectOption('#area_unit','sqm');assert.equal(await p.locator('#area_hectares').inputValue(),'1');
  assert((await p.locator('#inventory-fee-value').textContent()).includes('1,200'));
  await p.click('#application-next');await p.fill('#estimated-tree-count','4');await p.click('#generate-tree-table');assert.equal(await p.locator('#tree-records [data-tree-row]').count(),4);
  for(let i=0;i<4;i++){await p.selectOption('#tree_'+i+'_species_id',{index:1});await p.selectOption('#tree_'+i+'_tree_origin','planted');assert(await p.locator('#tree_'+i+'_scientific_name').inputValue());assert.equal(await p.locator('#tree_'+i+'_scientific_name').getAttribute('readonly'),'');}
  await p.selectOption('#tree_3_species_id','other');await p.fill('#tree_3_common_name','Custom tree');await p.fill('#tree_3_scientific_name','Custom scientific name');
  await Promise.all([p.waitForNavigation({waitUntil:'networkidle'}),p.getByRole('button',{name:'Save draft',exact:true}).click()]);
  const app=Number(new URL(p.url()).searchParams.get('id'));assert(app>0);
  const saved=query('SELECT area_hectares,municipality,barangay FROM tbl_permit_applications WHERE id=? AND applicant_user_id=?',[app,owner])[0];
  assert.equal(Number(saved.area_hectares),1);assert.equal(saved.barangay,'Bubukal');assert.equal(await p.locator('#tree-records [data-tree-row]').count(),4);
  await p.fill('#estimated-tree-count','130');await p.click('#generate-tree-table');
  await p.locator('#tree-records').evaluate(body=>{body.querySelectorAll('[data-tree-row]').forEach(row=>{const select=row.querySelector('[data-tree-input="species_id"]');if(!select.value){select.selectedIndex=1;select.dispatchEvent(new Event('change',{bubbles:true}));row.querySelector('[data-tree-input="tree_origin"]').value='planted';}});});
  const requestPromise=p.waitForRequest(request=>request.method()==='POST'&&request.url().includes('permit-application.php'));
  await Promise.all([p.waitForNavigation({waitUntil:'networkidle'}),p.getByRole('button',{name:'Save draft',exact:true}).click()]);
  const transport=new URLSearchParams((await requestPromise).postData());assert(transport.has('trees_json'));assert(![...transport.keys()].some(key=>key.startsWith('trees[')));
  assert.equal(Number(query('SELECT COUNT(*) AS n FROM tbl_permit_trees WHERE application_id=?',[app])[0].n),130);
  const post=await p.locator('#permit-form').evaluate(form=>Object.fromEntries(new FormData(form)));Object.assign(post,{action:'save_draft',area_unit:'invalid',area_value:'100',trees_json:'[]'});
  const badArea=await client.request.post(base+'community/permit-application.php',{form:post});assert((await badArea.text()).includes('Select a valid area unit'));
  assert.equal(Number(query('SELECT area_hectares FROM tbl_permit_applications WHERE id=?',[app])[0].area_hectares),1);
  await staff.goto(base+'ems/seedling-providers.php',{waitUntil:'networkidle'});
  for(const [id,value] of Object.entries({provider_name:providerName,address:'Temporary provider address',data_source:'Automated test fixture',latitude:'14.2845',longitude:'121.414'}))await staff.fill('#'+id,value);
  await staff.selectOption('#municipality','Santa Cruz');await staff.selectOption('#barangay','Bubukal');
  await Promise.all([staff.waitForNavigation({waitUntil:'networkidle'}),staff.getByRole('button',{name:'Save provider',exact:true}).click()]);
  assert((await staff.locator('[role=alert]:visible').textContent()).includes('saved'));const provider=Number(new URL(staff.url()).searchParams.get('edit'));assert(provider>0);
  const providerForm=await staff.locator('form[action="seedling-provider-action.php"]').evaluate(form=>Object.fromEntries(new FormData(form)));
  assert.equal((await ems.request.post(base+'ems/seedling-provider-action.php',{form:{...providerForm,csrf_token:'invalid'},maxRedirects:0})).status(),403);
  assert.equal((await client.request.post(base+'ems/seedling-provider-action.php',{form:providerForm,maxRedirects:0})).status(),302);
  const outside=await ems.request.post(base+'ems/seedling-provider-action.php',{form:{...providerForm,latitude:'14.6',longitude:'120.98'}});assert((await outside.text()).includes('Pin a valid location within Laguna.'));
  assert.equal(query('SELECT latitude FROM tbl_seedling_providers WHERE id=?',[provider])[0].latitude,'14.2845000');
  const inventory=Number(query('SELECT id FROM tbl_seedling_inventory WHERE is_active=1 LIMIT 1')[0].id);
  const request=JSON.parse(php('require $argv[1];require $argv[2];echo json_encode(seedling_submit_request($pdo,(int)$argv[3],json_decode($argv[4],true)));',['config/config.php','includes/seedling.php',owner,JSON.stringify({submission_key:crypto.randomBytes(32).toString('hex'),planting_purpose:'Temporary probe',planting_location:'Original description',planting_municipality:'Santa Cruz',planting_barangay:'Bubukal',inventory_id:[String(inventory)],quantity:['1']})]));requestId=Number(request.request_id);
  await staff.goto(base+'ems/seedling-request-detail.php?id='+requestId,{waitUntil:'networkidle'});
  await staff.selectOption('#municipality','Pagsanjan');await staff.selectOption('#barangay','Sabang');await staff.fill('#destinationLat','14.271');await staff.fill('#destinationLng','121.4555');await staff.fill('#destinationReason','理由'.repeat(500));
  await Promise.all([staff.waitForNavigation({waitUntil:'networkidle'}),staff.getByRole('button',{name:'Update destination',exact:true}).click()]);
  assert((await staff.locator('[role=alert]:visible').textContent()).includes('updated'));
  const corrected=query('SELECT planting_barangay,planting_location,planting_location_source FROM tbl_seedling_requests WHERE id=?',[requestId])[0];assert.equal(corrected.planting_barangay,'Sabang');assert.equal(corrected.planting_location,'Original description');assert.equal(corrected.planting_location_source.length,1000);
  assert(Number(query("SELECT COUNT(*) AS n FROM tbl_audit_trail WHERE entity_type='seedling_request' AND entity_id=? AND action='request_destination_corrected'",[requestId])[0].n)>0);
  const destForm=await staff.locator('form[action="seedling-request-destination-action.php"]').evaluate(form=>Object.fromEntries(new FormData(form)));
  assert((await (await ems.request.post(base+'ems/seedling-request-destination-action.php',{form:{...destForm,reason:''}})).text()).includes('Provide a reason'));
  assert.equal((await client.request.post(base+'ems/seedling-request-destination-action.php',{form:destForm,maxRedirects:0})).status(),302);
  await p.goto(base+'community/seedling-requests.php',{waitUntil:'networkidle'});await p.selectOption('#planting_municipality','Liliw');await p.selectOption('#planting_barangay','Kanlurang Bukal');
  assert.equal(await p.locator('#planting_province option[value="Laguna"]').count(),1);assert(await p.locator('#seedlingDistributionMap .leaflet-marker-pane').count());
  for(const page of [p,staff]){await page.setViewportSize({width:390,height:844});await page.reload({waitUntil:'networkidle'});assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'mobile overflow');}
  await p.setViewportSize({width:1536,height:1000});await p.goto(base+'community/permit-application.php?id='+app+'&step=4',{waitUntil:'networkidle'});
  await p.screenshot({path:path.join(require('os').tmpdir(),'certreefy-revision-table.png')});
  await p.setViewportSize({width:390,height:844});await p.reload({waitUntil:'networkidle'});
  const fits=await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2);
  if(!fits){console.log(await p.evaluate(()=>({viewport:innerWidth,width:document.documentElement.scrollWidth,wide:[...document.querySelectorAll('body *')].filter(e=>e.getBoundingClientRect().width>innerWidth+2&&!e.closest('table')).slice(0,16).map(e=>({tag:e.tagName,id:e.id,cls:e.className,w:e.getBoundingClientRect().width,parent:e.parentElement.className,overflow:getComputedStyle(e.parentElement).overflow,parentW:e.parentElement.getBoundingClientRect().width,left:e.getBoundingClientRect().left,right:e.getBoundingClientRect().right}))})));await p.screenshot({path:path.join(require('os').tmpdir(),'certreefy-revision-mobile.png')});}
  assert(fits,'mobile permit table overflows page');
  assert(await p.locator('.permit-tree-table').evaluate(table=>table.parentElement.scrollWidth>table.parentElement.clientWidth),'mobile permit table must scroll');
  assert.deepEqual(errors,[]);console.log(JSON.stringify({cascades:true,areaConversionAndFees:true,generatedRows:true,largeTable130Rows:true,speciesAutofillAndOther:true,draftPersistence:true,providerCreate:true,csrfAndRoleDenial:true,destinationCorrectionAndAudit:true,mobile:true,errors}));
 }finally{
  if(browser)await browser.close();
  assert.equal(query('SELECT username FROM tbl_users WHERE id=?',[owner])[0].username,username);
  const commands=[];
  for(const app of query('SELECT id FROM tbl_permit_applications WHERE applicant_user_id=?',[owner]))for(const table of ['tbl_permit_trees','tbl_permit_status_history','tbl_permit_applications'])commands.push(['DELETE FROM '+table+' WHERE '+(table==='tbl_permit_applications'?'id':'application_id')+'=?',[app.id]]);
  for(const r of query('SELECT id FROM tbl_seedling_requests WHERE requester_user_id=?',[owner])){
   for(const table of ['tbl_seedling_request_items','tbl_seedling_request_status_history'])commands.push(['DELETE FROM '+table+' WHERE request_id=?',[r.id]]);
   commands.push(["DELETE FROM tbl_audit_trail WHERE entity_type='seedling_request' AND entity_id=?",[r.id]],['DELETE FROM tbl_seedling_requests WHERE id=?',[r.id]]);
  }
  for(const provider of query("SELECT id FROM tbl_seedling_providers WHERE provider_name=? AND data_source='Automated test fixture'",[providerName]))commands.push(["DELETE FROM tbl_audit_trail WHERE entity_type='seedling_provider' AND entity_id=?",[provider.id]],['DELETE FROM tbl_seedling_providers WHERE id=?',[provider.id]]);
  commands.push(['DELETE FROM tbl_audit_trail WHERE actor_user_id=?',[owner]],['DELETE FROM tbl_notifications WHERE recipient_user_id=? OR created_by_user_id=?',[owner,owner]],['DELETE FROM tbl_users WHERE id=?',[owner]]);
  php('require $argv[1];$pdo->beginTransaction();foreach(json_decode($argv[2],true) as $item){$pdo->prepare($item[0])->execute($item[1]);}$pdo->commit();',['config/config.php',JSON.stringify(commands)]);
  for(const sid of sessions)php('session_id($argv[1]);session_start();session_destroy();',[sid]);
 }
})().catch(error=>{console.error(error);process.exit(1)});
