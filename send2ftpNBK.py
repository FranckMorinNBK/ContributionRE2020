import paramiko
import os
from dotenv import load_dotenv

load_dotenv()

HOST     = "ftp.cluster113.hosting.ovh.net"
PORT     = 22
USER     = "sdarbxbx-outils"
PASSWORD = os.getenv("FTP_PASSWORD")

LOCAL_DIR   = os.path.dirname(__file__)
FILES       = ["index.html", "inies.php"]
REMOTE_DIR  = "contributionRE2020"

def upload():
    transport = paramiko.Transport((HOST, PORT))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)

    # Crée le dossier distant s'il n'existe pas
    try:
        sftp.stat(REMOTE_DIR)
    except FileNotFoundError:
        sftp.mkdir(REMOTE_DIR)
        print(f"Dossier '{REMOTE_DIR}' créé.")

    for name in FILES:
        local_file  = os.path.join(LOCAL_DIR, name)
        remote_file = f"{REMOTE_DIR}/{name}"
        sftp.put(local_file, remote_file)
        print(f"'{local_file}' → '{remote_file}' : envoi OK")

    sftp.close()
    transport.close()

if __name__ == "__main__":
    upload()
