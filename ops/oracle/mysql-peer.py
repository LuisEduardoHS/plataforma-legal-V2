#!/usr/bin/env python3
"""Local Linux peer attestation; root enrollment, unprivileged verification."""
import configparser, json, os, pathlib, pwd, socket, stat, struct, sys
POLICY=pathlib.Path('/etc/lefenda/mysql-identity.json')
def fail(): raise RuntimeError('Local MySQL identity not accredited')
def parents(path, allowed=(0,)):
    for p in [path.parent, *path.parent.parents]:
        s=p.stat()
        if s.st_uid not in allowed or s.st_mode & 0o022: fail()
def peer(path):
    s=path.lstat()
    if not stat.S_ISSOCK(s.st_mode) or s.st_uid==0: fail()
    parents(path,(0,s.st_uid))
    with socket.socket(socket.AF_UNIX) as c:
        c.connect(str(path)); pid,uid,gid=struct.unpack('3i',c.getsockopt(socket.SOL_SOCKET,socket.SO_PEERCRED,12))
    if uid!=s.st_uid: fail()
    return s,pid,uid
try:
    if len(sys.argv)>1 and sys.argv[1]=='enroll':
        if os.geteuid()!=0 or len(sys.argv)!=4: fail()
        path=pathlib.Path(sys.argv[2]); data=pathlib.Path(sys.argv[3]).resolve(strict=True)
        s,pid,uid=peer(path)
        if pwd.getpwuid(uid).pw_name!='mysql' or data.stat().st_uid!=uid or data.stat().st_mode & 0o022: fail()
        auto=data/'auto.cnf'; a=auto.stat()
        if a.st_uid!=uid or a.st_mode & 0o022: fail()
        cfg=configparser.ConfigParser();cfg.read(auto)
        record=dict(socket=str(path),datadir=str(data),uuid=cfg['auto']['server-uuid'],pid=pid,uid=uid,inode=s.st_ino,device=s.st_dev)
        POLICY.parent.mkdir(mode=0o755,exist_ok=True);parents(POLICY)
        fd=os.open(POLICY,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o644)
        os.fchmod(fd,0o644)
        with os.fdopen(fd,'w') as f: json.dump(record,f)
        print('Local identity enrolled from protected MySQL process and auto.cnf')
    else:
        parents(POLICY);s=POLICY.lstat()
        if not stat.S_ISREG(s.st_mode) or s.st_uid!=0 or s.st_mode & 0o022: fail()
        record=json.loads(POLICY.read_text());s,pid,uid=peer(pathlib.Path(record['socket']))
        if (pid,uid,s.st_ino,s.st_dev)!=(record['pid'],record['uid'],record['inode'],record['device']): fail()
        print(json.dumps(record))
except Exception:
    print('Local MySQL identity rejected',file=sys.stderr);sys.exit(2)
