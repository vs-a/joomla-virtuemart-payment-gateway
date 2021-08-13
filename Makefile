ARCHIVENAME = frisbee-payment-gateway.zip

build:
	zip -r "$(ARCHIVENAME)" ./admin/ script.vmfrisbee.php index.html install.xml README.md frisbee.xml
