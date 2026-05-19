<?php
require 'vendor/autoload.php';

use Aws\CloudWatch\CloudWatchClient;
use Aws\Exception\AwsException;
// ⭐ AWS SDK에서 제공하는 메타데이터 프로바이더 로드
use Aws\Ec2\Metadata\InstanceMetadataProvider; 

$timestamps = [];
$values = [];
$error_message = null;

try {
    // 1. AWS SDK 공식 기능으로 인스턴스 ID와 리전 가져오기 (IP 주소 하드코딩 탈출!)
    $provider = new InstanceMetadataProvider();
    
    // 비동기(Promise) 방식으로 작동하므로 wait()를 붙여서 값을 바로 받아옵니다.
    $my_instance_id = $provider->getInstanceId()->wait();
    $az = $provider->getAvailabilityZone()->wait(); // 예: ap-northeast-2a
    $my_region = substr($az, 0, -1);               // 가용구역에서 뒤에 알파벳 제거

} catch (\Exception $e) {
    // 메타데이터 조회 실패 시 기본값 세팅 (로컬 테스트용 예외처리)
    $my_instance_id = "i-placeholder";
    $my_region = "ap-northeast-2";
}

// 2. CloudWatch 클라이언트 생성
$cwClient = new CloudWatchClient([
    'region'  => $my_region,
    'version' => 'latest'
]);

try {
    $result = $cwClient->getMetricData([
        'MetricDataQueries' => [
            [
                'Id' => 'm1',
                'MetricStat' => [
                    'Metric' => [
                        'Namespace' => 'AWS/EC2',
                        'MetricName' => 'CPUUtilization',
                        'Dimensions' => [
                            ['Name' => 'InstanceId', 'Value' => $my_instance_id]
                        ]
                    ],
                    'Period' => 60, 
                    'Stat' => 'Average',
                ],
            ],
        ],
        'StartTime' => strtotime('-3 hours'), 
        'EndTime' => time(),
    ]);

    if (!empty($result['MetricDataResults'][0]['Timestamps'])) {
        foreach ($result['MetricDataResults'][0]['Timestamps'] as $dt) {
            $timestamps[] = $dt->format('H:i'); 
        }
        $timestamps = array_reverse($timestamps);
        $values = array_reverse($result['MetricDataResults'][0]['Values']);
    }

} catch (AwsException $e) {
    $error_message = $e->getMessage();
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>EC2 CPU 모니터링</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div style="width: 600px; margin: 50px auto; text-align: center;">
        <h2>🖥️ EC2 CPU 사용량 (1분 단위)</h2>

    <script>
        const chartLabels = <?php echo json_encode($timestamps); ?>;
        const chartData = <?php echo json_encode($values); ?>;

        if (chartLabels.length > 0) {
            const ctx = document.getElementById('cpuChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: chartLabels,
                    datasets: [{
                        label: 'CPU 사용량 (%)',
                        data: chartData,
                        borderColor: 'rgba(54, 162, 235, 1)',
                        backgroundColor: 'rgba(54, 162, 235, 0.2)',
                        borderWidth: 2,
                        tension: 0.1
                    }]
                },
                options: {
                    scales: { y: { beginAtZero: true, max: 100 } }
                }
            });
        }
    </script>
</body>
</html>
