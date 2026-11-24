# MuDeskClient - https://miguelenred.es/mudeskclient - Apache License 2.0
"""End-to-end test in Firefox (Selenium + geckodriver).

python3 tests/e2e_firefox.py <baseUrl> <admin> <pass> <connName> <outDir>
Environment: FIREFOX (binary), GECKODRIVER, XCMD (prefix to run commands on the remote X display), XDISPLAY_SHOT (command printing a PNG of the remote screen to stdout).
"""
import base64, hashlib, json, os, subprocess, sys, time, zipfile

from selenium import webdriver
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.firefox.service import Service
from selenium.webdriver.support.ui import WebDriverWait

base, admin, password, conn, out = sys.argv[1:6]
X = os.environ.get('XCMD', '')
results = {}


def log(*a):
    print(time.strftime('%H:%M:%S'), *a, flush=True)


opts = webdriver.FirefoxOptions()
opts.binary_location = os.environ['FIREFOX']
opts.add_argument('-headless')
opts.set_preference('browser.download.folderList', 2)
opts.set_preference('browser.download.dir', out)
opts.set_preference('browser.helperApps.neverAsk.saveToDisk', 'application/zip,application/octet-stream')
opts.set_preference('media.autoplay.default', 0)
opts.set_preference('dom.events.asyncClipboard.readText', True)
d = webdriver.Firefox(options=opts, service=Service(os.environ['GECKODRIVER']))
d.set_window_size(1200, 900)
wait = WebDriverWait(d, 30)
log('firefox', d.capabilities.get('browserVersion'))

ERRORS = "window.__err=[];addEventListener('error',e=>__err.push(e.message));addEventListener('unhandledrejection',e=>__err.push(String(e.reason)));"

d.get(base + '/index.php')
wait.until(lambda x: x.find_elements(By.NAME, 'username'))
d.execute_script(ERRORS)
d.find_element(By.NAME, 'username').send_keys(admin)
d.find_element(By.NAME, 'password').send_keys(password)
d.find_element(By.CSS_SELECTOR, 'button.primary').click()
wait.until(lambda x: x.find_elements(By.CSS_SELECTOR, '.conn'))
href = d.execute_script("return [...document.querySelectorAll('.conn')].find(c => c.textContent.includes(arguments[0])).querySelector('a.btn.primary').getAttribute('href')", conn)
d.get(base + '/index.php' + href)
wait.until(lambda x: x.find_elements(By.CSS_SELECTOR, '.overlay form'))
d.execute_script(ERRORS)
log('connect dialog options:', d.execute_script("return [...document.querySelectorAll('.overlay form input[type=checkbox]')].map(i => i.name + '=' + i.checked).join(' ')"))
d.find_element(By.CSS_SELECTOR, '.overlay form button.primary').click()
# a computer never seen: its certificate's fingerprints shown first, trusted here (the test's own computer)
wait.until(lambda x: x.execute_script("const o = document.querySelector('.overlay'); return o.classList.contains('hidden') || !!o.querySelector('.cert-prints')"))
if d.execute_script("return !!document.querySelector('.overlay:not(.hidden) .cert-prints')"):
    d.find_element(By.CSS_SELECTOR, '.overlay button.primary').click()
wait.until(lambda x: x.execute_script("return document.querySelector('.overlay').classList.contains('hidden')"))
time.sleep(3)
results['connected'] = True
size = d.execute_script("const c=document.querySelector('canvas');return [c.width,c.height]")
log('connected, remote size', size)

# the picture must match the remote screen
png = base64.b64decode(d.execute_script("return document.querySelector('canvas').toDataURL('image/png').split(',')[1]"))
open(out + '/ff-canvas.png', 'wb').write(png)
subprocess.run(os.environ['XDISPLAY_SHOT'] + ' > ' + out + '/ff-remote.png', shell=True, check=True)
diff = subprocess.run(['compare', '-metric', 'AE', '-fuzz', '6%', out + '/ff-remote.png', out + '/ff-canvas.png', 'null:'], capture_output=True, text=True).stderr.strip()
results['picture_diff_pixels'] = diff
log('canvas vs remote screen, different pixels:', diff)

# keyboard: type into a terminal on the remote screen, read it back through the remote clipboard
canvas = d.find_element(By.TAG_NAME, 'canvas')
subprocess.run(X + " sh -c 'xterm -geometry 60x6+30+30 -e sh -c \"cat > /tmp/fftyped.txt\" >/dev/null 2>&1 &'", shell=True)
time.sleep(2)
webdriver.ActionChains(d).move_to_element_with_offset(canvas, -size[0] // 2 + 200, -size[1] // 2 + 60).click().perform()
time.sleep(0.5)
kbd = d.find_element(By.CSS_SELECTOR, 'textarea.kbd')
kbd.send_keys('hola firefox 12345')
kbd.send_keys(Keys.ENTER)
time.sleep(1.5)
typed = subprocess.run(X + ' cat /tmp/fftyped.txt', shell=True, capture_output=True, text=True).stdout.strip()
results['typed'] = typed
log('typed on the remote:', repr(typed))

# clipboard remote -> local and local -> remote through the panel
subprocess.run("printf 'remoto en firefox ñ' | " + X + ' xclip -i -selection clipboard >/dev/null 2>&1', shell=True)
time.sleep(2.5)
d.find_element(By.CSS_SELECTOR, '.bar button[data-p=clip]').click()
time.sleep(0.5)
results['clip_remote_to_local'] = d.find_element(By.CSS_SELECTOR, '.panel textarea').get_property('value')
log('remote->local:', repr(results['clip_remote_to_local']))
ta = d.find_element(By.CSS_SELECTOR, '.panel textarea')
ta.clear()
ta.send_keys('local desde firefox áé')
d.find_element(By.CSS_SELECTOR, '.panel .btn.primary').click()
time.sleep(2.5)
results['clip_local_to_remote'] = subprocess.run(X + ' timeout 3 xclip -o -selection clipboard', shell=True, capture_output=True, text=True).stdout
log('local->remote:', repr(results['clip_local_to_remote']))
d.find_element(By.CSS_SELECTOR, '.bar button[data-p=clip]').click()

# files local -> remote through the files panel
open('/tmp/ff1.txt', 'w').write('archivo desde firefox\n')
open('/tmp/ff2.bin', 'wb').write(os.urandom(400000))
d.find_element(By.CSS_SELECTOR, '.bar button[data-p=files]').click()
time.sleep(0.5)
d.find_element(By.CSS_SELECTOR, '.panel input[type=file]').send_keys('/tmp/ff1.txt\n/tmp/ff2.bin')
time.sleep(5)
md5 = lambda p: hashlib.md5(open(p, 'rb').read()).hexdigest()
subprocess.run(X + ' timeout 3 xclip -o -selection clipboard -t text/uri-list', shell=True, capture_output=True)  # xrdp fills .clipboard on request
remote = subprocess.run(X + ' md5sum /home/rdpuser/thinclient_drives/.clipboard/ff1.txt /home/rdpuser/thinclient_drives/.clipboard/ff2.bin', shell=True, capture_output=True, text=True).stdout.split()
results['files_local_to_remote'] = remote[0::2] == [md5('/tmp/ff1.txt'), md5('/tmp/ff2.bin')]
if not results['files_local_to_remote']:
    log('remote md5:', remote, 'local:', md5('/tmp/ff1.txt'), md5('/tmp/ff2.bin'))
log('files local->remote identical:', results['files_local_to_remote'])

# files remote -> local download (zip)
subprocess.run(X + " sh -c \"printf 'A\\n' > /tmp/rfa.txt; head -c 700000 /dev/urandom > /tmp/rfb.bin; printf 'copy\\nfile:///tmp/rfa.txt\\nfile:///tmp/rfb.bin' | xclip -i -selection clipboard -t x-special/gnome-copied-files >/dev/null 2>&1\"", shell=True)
time.sleep(3)
d.find_element(By.CSS_SELECTOR, '.bar button[data-p=files]').click()
d.find_element(By.CSS_SELECTOR, '.bar button[data-p=files]').click()
time.sleep(0.5)
before = set(os.listdir(out))
d.execute_script("[...document.querySelectorAll('.panel .btn.primary')].find(b => /Descargar|Download/.test(b.textContent)).click()")
zipname = None
for _ in range(30):
    time.sleep(1)
    new = [f for f in set(os.listdir(out)) - before if f.endswith('.zip')]
    if new:
        zipname = os.path.join(out, new[0])
        break
ok = False
if zipname:
    time.sleep(1)
    z = zipfile.ZipFile(zipname)
    got = [hashlib.md5(z.read(n)).hexdigest() for n in ('rfa.txt', 'rfb.bin')]
    orig = subprocess.run('sudo md5sum /tmp/rfa.txt /tmp/rfb.bin', shell=True, capture_output=True, text=True).stdout.split()[0::2]
    ok = got == orig
results['files_remote_to_local_zip'] = ok
log('files remote->local zip identical:', ok)

# audio: the player is created and running after a user gesture
d.execute_script("const b=document.createElement('button');b.id='aud';b.textContent='a';b.style.cssText='position:fixed;left:0;bottom:0;z-index:99';b.onclick=()=>{const a=new AudioContext();window.__aud='created:'+a.state;a.resume().then(()=>{window.__aud=a.state;a.close();},(e)=>{window.__aud='error:'+e;});};document.body.append(b);")
d.find_element(By.ID, 'aud').click()
time.sleep(3)
results['audio_context'] = d.execute_script('return window.__aud')
log('AudioContext state after a click:', results['audio_context'])

# resize the window -> new remote resolution
d.set_window_size(1000, 700)
time.sleep(7)
results['resized_to'] = d.execute_script("const c=document.querySelector('canvas');return [c.width,c.height]")
log('after window resize:', results['resized_to'])

results['errors'] = d.execute_script('return window.__err')
log('page errors:', results['errors'])
d.quit()
print(json.dumps(results))
