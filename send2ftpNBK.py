import paramiko
import os
from dotenv import load_dotenv

load_dotenv()

HOST     = "ftp.cluster113.hosting.ovh.net"
PORT     = 22
USER     = "sdarbxbx-outils"
PASSWORD = os.getenv("FTP_PASSWORD")

LOCAL_FILE  = os.path.join(os.path.dirname(__file__), "index.html")
REMOTE_DIR  = "contributionRE2020"
REMOTE_FILE = f"{REMOTE_DIR}/index.html"

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

    sftp.put(LOCAL_FILE, REMOTE_FILE)
    print(f"'{LOCAL_FILE}' → '{REMOTE_FILE}' : envoi OK")

    sftp.close()
    transport.close()

if __name__ == "__main__":
    upload()
