<?php
class RecSys{
    public function calCoItem(){
        $topN = 20;//超参数，选择Top20，越大可能会推荐长尾产品
        $Model = new \Think\Model();
        $dataList = array();
        $begId = 0;
        $showNum = 10000;
        $dealNum = 0;
        $CoItemMap = array();//商品共现矩阵
        $buyItemUserMap = array();//买了商品的用户表
        do{
            $sql = "select * from mid_zale_user_item_map where uid>$begId limit $showNum";//mid_zale_user_item_map是用户-商品倒排表
            $dataList = $Model->query($sql);
            if($dataList === false){
                return false;
            }
            
            foreach($dataList as $data){
                $begId = $data["Fuid"];
                $productIdList = explode(";",$data["Fproduct_id_list"]);
                if(empty($buyItemUserMap[$productIdList[0]])){
                    $buyItemUserMap[$productIdList[0]]=0;
                }
                $buyItemUserMap[$productIdList[0]]++;
                $productIdListLen = count($productIdList);
                for($i=1;$i<$productIdListLen;$i++){
                    if(empty($CoItemMap[$productIdList[0]][$productIdList[$i]])){
                        $CoItemMap[$productIdList[0]][$productIdList[$i]] = 0;
                    }
                    $CoItemMap[$productIdList[0]][$productIdList[$i]]+=(1/log(1+$productIdListLen));//惩罚热门用户，这类用户会污染推荐结果
                    if(empty($CoItemMap[$productIdList[$i]][$productIdList[0]])){
                        $CoItemMap[$productIdList[$i]][$productIdList[0]] = 0;
                    }
                    $CoItemMap[$productIdList[$i]][$productIdList[0]]=$CoItemMap[$productIdList[0]][$productIdList[$i]];
                    if(empty($buyItemUserMap[$productIdList[$i]])){
                        $buyItemUserMap[$productIdList[$i]]=0;
                    }
                    $buyItemUserMap[$productIdList[$i]]++;
                }
            }
            $dealNum+=$showNum;
        }while(!empty($dataList));
        
        $similarMap = array();
        foreach($CoItemMap as $i=>$coItemList){
            foreach($coItemList as $j=>$coItemNum){
                $similarMap[$i][$j] = round($coItemNum/sqrt($buyItemUserMap[$i]*$buyItemUserMap[$j]),7);//计算商品相似矩阵
            }
            arsort($similarMap[$i]);
            $tmpList = array();
            $num = 0;
            foreach($similarMap[$i] as $k=>$v){
                if($num >= $topN){
                    break;
                }
                $tmpList[$k] = $v;
                $num++;
            }
            $similarMap[$i] = $tmpList;
        }
        S("zale_similarMap",$similarMap,86400);//保存商品相似矩阵到缓存
    }
    public function getRec(){
        $topN = 10;//超参数
        $insuredFactor = 1;//超参数，已生效影响因子
        $payedFactor = 0.5;//超参数，已支付未生效影响因子
        $dealFactor = 0.2;//超参数，下单未支付产品影响因子
        
        $uid = I("custom.uid");
        $Model = new \Think\Model();
        $sql = "select * from deal where uid=$uid";//获取这个用户下过的所有订单
        $dataList = $Model->query($sql);
        if($dataList === false){
            return false;
        }
        $insuredProductIdList = array();//生效产品
        $payProductIdList = array();//未生效但支付过产品
        $dealProductIdList = array();//下单未支付产品
        foreach($dataList as $data){
            if($data["deal_state"] == "已生效")
                $insuredProductIdList[$data["product_id"]] = 1;
        }
        foreach($dataList as $data){
            if($data["deal_state"] == "支付过")
                if(empty($insuredProductIdList[$data["Fproduct_id"]]))//过滤已生效产品
                    $payProductIdList[$data["Fproduct_id"]] = 1;
        }
        foreach($dataList as $data){
            if(empty($insuredProductIdList[$data["Fproduct_id"]]) && empty($payProductIdList[$data["Fproduct_id"]]))//过滤已生效、已支付产品
                $dealProductIdList[$data["Fproduct_id"]] = 1;
        }
        $recItemList = array();
        $similarMap = S("zale_similarMap");
        foreach($insuredProductIdList as $i => $v){
            foreach($similarMap[$i] as $j => $similar){
                if(empty($recItemList[$j])){
                    $recItemList[$j] = 0;
                }
                $recItemList[$j] += round($insuredFactor*$similar,7);
            }
        }
        foreach($payProductIdList as $i => $v){
            foreach($similarMap[$i] as $j => $similar){
                if(empty($recItemList[$j])){
                    $recItemList[$j] = 0;
                }
                $recItemList[$j] += round($payedFactor*$similar,7);
            }
        }
        foreach($dealProductIdList as $i => $v){
            foreach($similarMap[$i] as $j => $similar){
                if(empty($recItemList[$j])){
                    $recItemList[$j] = 0;
                }
                $recItemList[$j] += round($dealFactor*$similar,7);
            }
        }
        arsort($recItemList);
        $tmpList = array();
        $num = 0;
        foreach($recItemList as $j=>$v){
            if($num >= $topN){
                break;
            }
            if(empty($insuredProductIdList[$j])){
                $tmpList[$j] = $v;
                $num++;
            }
        }
        echo json_encode($recItemList)."\n";//输出最终的推荐结果
    }
    
}