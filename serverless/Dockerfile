# AWS Lambda container image running PHP through a custom runtime.
FROM public.ecr.aws/lambda/provided:al2023

RUN microdnf -y install php-cli php-mbstring && microdnf clean all

COPY handler.php runtime.php /var/task/
COPY bootstrap /var/runtime/bootstrap
RUN chmod 755 /var/runtime/bootstrap

CMD ["handler"]
